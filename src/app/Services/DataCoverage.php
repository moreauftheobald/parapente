<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Balise;
use App\Models\Site;
use App\Models\WeatherModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mesure la couverture réelle des données météo collectées pour
 * l'écran d'administration « Couverture des données ».
 *
 * 4 sections :
 *   1. Fraîcheur des fetches par modèle météo (dernier fetch, run
 *      provider, décalage prévu/réalisé, lignes upsertées).
 *   2. Couverture des prévisions sites (table `forecasts`) sur J → J+4.
 *   3. Couverture des prévisions balises (`forecast_archive_balises`)
 *      sur J-7 → J+5.
 *   4. Couverture des relevés balises (`balise_readings_hourly`) sur
 *      J-7 → J.
 *
 * Une cellule de couverture représente :
 *   heures_distinctes_avec_donnée / (24 × nb_unités_actives) en %
 *
 * Tous les résultats sont caches Redis 5 minutes — l'écran est consulté
 * occasionnellement, ça suffit largement et ça évite de relancer les
 * agrégations à chaque rafraîchissement de page.
 */
class DataCoverage
{
    private const CACHE_TTL_S = 300;

    /** Périmètre des prévisions sites : 5 jours futurs (J → J+4) */
    private const SITE_FORECAST_DAYS_FUTURE = 5;

    /** Périmètre des prévisions balises : J-7 → J+5 */
    private const BALISE_FORECAST_DAYS_PAST   = 7;
    private const BALISE_FORECAST_DAYS_FUTURE = 6; // J inclus + 5 → 6 colonnes futur

    /** Périmètre des relevés balises : J-7 → J */
    private const BALISE_READINGS_DAYS_PAST = 7;

    /**
     * Vide les caches calculés. À appeler après un fetch manuel si on
     * veut une mise à jour immédiate de l'écran (non câblé pour l'instant).
     */
    public function flush(): void
    {
        Cache::forget('data_coverage.model_freshness');
        Cache::forget('data_coverage.site_forecasts');
        Cache::forget('data_coverage.balise_forecasts');
        Cache::forget('data_coverage.balise_readings');
    }

    /**
     * Section 1 — Fraîcheur des modèles météo.
     *
     * @return array<int, array{
     *     model: WeatherModel,
     *     last_fetch_site: ?Carbon,
     *     last_fetch_balise: ?Carbon,
     *     last_rows_site: ?int,
     *     last_rows_balise: ?int,
     *     last_provider_run: ?Carbon,
     *     expected_interval_min: int,
     *     observed_interval_min: ?int,
     *     drift_pct: ?float,
     * }>
     */
    public function modelFreshness(): array
    {
        return Cache::remember('data_coverage.model_freshness', self::CACHE_TTL_S, function () {
            $models = WeatherModel::query()->orderBy('name')->get();

            // Tous les derniers fetches par (modèle, scope) en une seule
            // requête : on récupère les 2 dernières lignes par modèle &
            // scope pour calculer l'intervalle observé.
            $logs = DB::table('weather_fetch_log')
                ->select('weather_model_id', 'scope', 'fetched_at', 'rows_upserted', 'provider_run_at')
                ->orderByDesc('fetched_at')
                ->get()
                ->groupBy(fn ($r) => $r->weather_model_id . '|' . $r->scope);

            $rows = [];
            foreach ($models as $model) {
                $siteLogs   = $logs->get($model->id . '|site',   collect());
                $baliseLogs = $logs->get($model->id . '|balise', collect());

                // Combine les scopes pour l'intervalle observé (le modèle
                // est fetché à la même cadence quel que soit le scope).
                $allLogs = $siteLogs->merge($baliseLogs)
                    ->sortByDesc('fetched_at')
                    ->values();

                $lastFetchSite   = $siteLogs->first()?->fetched_at;
                $lastFetchBalise = $baliseLogs->first()?->fetched_at;
                $lastRowsSite    = $siteLogs->first()?->rows_upserted;
                $lastRowsBalise  = $baliseLogs->first()?->rows_upserted;
                $lastProviderRun = $allLogs->first()?->provider_run_at;

                // Intervalle observé = minutes entre les 2 derniers fetches
                // (on cherche 2 timestamps distincts car un même run de
                // l'orchestrateur peut produire site+balise quasi-simultanés).
                $observedMin = null;
                if ($allLogs->count() >= 2) {
                    $latest = CarbonImmutable::parse((string) $allLogs[0]->fetched_at);
                    $prev   = null;
                    foreach ($allLogs->skip(1) as $log) {
                        $candidate = CarbonImmutable::parse((string) $log->fetched_at);
                        if ($latest->diffInMinutes($candidate, true) >= 1) {
                            $prev = $candidate;
                            break;
                        }
                    }
                    if ($prev !== null) {
                        $observedMin = (int) round($latest->diffInMinutes($prev, true));
                    }
                }

                $expectedMin = max(1, (int) $model->refresh_frequency_minutes);
                $driftPct    = $observedMin !== null
                    ? round((($observedMin - $expectedMin) / $expectedMin) * 100, 1)
                    : null;

                $rows[] = [
                    'model'                 => $model,
                    'last_fetch_site'       => $lastFetchSite ? Carbon::parse((string) $lastFetchSite) : null,
                    'last_fetch_balise'     => $lastFetchBalise ? Carbon::parse((string) $lastFetchBalise) : null,
                    'last_rows_site'        => $lastRowsSite !== null ? (int) $lastRowsSite : null,
                    'last_rows_balise'      => $lastRowsBalise !== null ? (int) $lastRowsBalise : null,
                    'last_provider_run'     => $lastProviderRun ? Carbon::parse((string) $lastProviderRun) : null,
                    'expected_interval_min' => $expectedMin,
                    'observed_interval_min' => $observedMin,
                    'drift_pct'             => $driftPct,
                ];
            }

            return $rows;
        });
    }

    /**
     * Section 2 — Couverture des prévisions sites sur J → J+4.
     *
     * @return array{
     *     days: array<int, CarbonImmutable>,
     *     rows: array<int, array{model: WeatherModel, cells: array<int, ?float>}>,
     *     sites_active: int,
     * }
     */
    public function siteForecastCoverage(): array
    {
        return Cache::remember('data_coverage.site_forecasts', self::CACHE_TTL_S, function () {
            $days = $this->dayRange(0, self::SITE_FORECAST_DAYS_FUTURE - 1);
            $sitesActive = Site::where('active', true)->count();
            $models = WeatherModel::query()->where('active', true)->orderBy('name')->get();

            if ($sitesActive === 0 || $models->isEmpty()) {
                return [
                    'days'         => $days,
                    'rows'         => [],
                    'sites_active' => $sitesActive,
                ];
            }

            $start = $days[0]->startOfDay();
            $end   = end($days)->endOfDay();

            // Une requête : nb d'heures distinctes (site_id, forecast_at)
            // par (modèle, jour). On joint sur sites.active=1 pour ne
            // compter que les sites actuellement actifs (dénominateur
            // cohérent).
            $rowsRaw = DB::table('forecasts')
                ->join('sites', 'sites.id', '=', 'forecasts.site_id')
                ->where('sites.active', true)
                ->whereBetween('forecasts.forecast_at', [$start, $end])
                ->selectRaw('forecasts.weather_model_id as model_id, DATE(forecasts.forecast_at) as day, COUNT(DISTINCT forecasts.site_id, forecasts.forecast_at) as n')
                ->groupBy('model_id', 'day')
                ->get();

            $byModelDay = [];
            foreach ($rowsRaw as $r) {
                $byModelDay[$r->model_id][$r->day] = (int) $r->n;
            }

            $expectedPerDay = 24 * $sitesActive;
            $rows = [];
            foreach ($models as $model) {
                $cells = [];
                foreach ($days as $day) {
                    $key = $day->format('Y-m-d');
                    $n   = $byModelDay[$model->id][$key] ?? 0;
                    $cells[] = $expectedPerDay > 0 ? round(($n / $expectedPerDay) * 100, 1) : null;
                }
                $rows[] = [
                    'model' => $model,
                    'cells' => $cells,
                ];
            }

            return [
                'days'         => $days,
                'rows'         => $rows,
                'sites_active' => $sitesActive,
            ];
        });
    }

    /**
     * Section 3 — Couverture des prévisions balises sur J-7 → J+5.
     *
     * @return array{
     *     days: array<int, CarbonImmutable>,
     *     rows: array<int, array{model: WeatherModel, cells: array<int, ?float>}>,
     *     balises_active: int,
     * }
     */
    public function baliseForecastCoverage(): array
    {
        return Cache::remember('data_coverage.balise_forecasts', self::CACHE_TTL_S, function () {
            $days = $this->dayRange(
                -self::BALISE_FORECAST_DAYS_PAST,
                self::BALISE_FORECAST_DAYS_FUTURE - 1
            );
            $balisesActive = Balise::where('active', true)->count();
            $models = WeatherModel::query()->where('active', true)->orderBy('name')->get();

            $payload = [
                'days'           => $days,
                'rows'           => [],
                'balises_active' => $balisesActive,
            ];

            if (! DB::getSchemaBuilder()->hasTable('forecast_archive_balises')
                || $balisesActive === 0
                || $models->isEmpty()
            ) {
                return $payload;
            }

            $start = $days[0]->startOfDay();
            $end   = end($days)->endOfDay();

            $rowsRaw = DB::table('forecast_archive_balises')
                ->join('balises', 'balises.id', '=', 'forecast_archive_balises.balise_id')
                ->where('balises.active', true)
                ->whereBetween('forecast_archive_balises.target_at', [$start, $end])
                ->selectRaw('forecast_archive_balises.weather_model_id as model_id, DATE(forecast_archive_balises.target_at) as day, COUNT(DISTINCT forecast_archive_balises.balise_id, forecast_archive_balises.target_at) as n')
                ->groupBy('model_id', 'day')
                ->get();

            $byModelDay = [];
            foreach ($rowsRaw as $r) {
                $byModelDay[$r->model_id][$r->day] = (int) $r->n;
            }

            $expectedPerDay = 24 * $balisesActive;
            $rows = [];
            foreach ($models as $model) {
                $cells = [];
                foreach ($days as $day) {
                    $key = $day->format('Y-m-d');
                    $n   = $byModelDay[$model->id][$key] ?? 0;
                    $cells[] = $expectedPerDay > 0 ? round(($n / $expectedPerDay) * 100, 1) : null;
                }
                $rows[] = [
                    'model' => $model,
                    'cells' => $cells,
                ];
            }

            $payload['rows'] = $rows;
            return $payload;
        });
    }

    /**
     * Section 4 — Couverture des relevés balises sur J-7 → J, groupés
     * par réseau (`balises.source` : pioupiou / metar / …).
     *
     * Pour chaque réseau on agrège la somme des heures reçues sur
     * toutes ses balises pour chaque jour, ramenée à
     * `24 × nb_balises_du_réseau`. Le détail par balise reste
     * disponible (utilisé par la vue pour le hide/show).
     *
     * @return array{
     *     days: array<int, CarbonImmutable>,
     *     groups: array<int, array{
     *         source: string,
     *         label: string,
     *         balises_count: int,
     *         aggregate_cells: array<int, ?float>,
     *         balises: array<int, array{balise: Balise, cells: array<int, ?float>, last_reading_at: ?Carbon}>,
     *     }>,
     * }
     */
    public function baliseReadingsCoverage(): array
    {
        return Cache::remember('data_coverage.balise_readings', self::CACHE_TTL_S, function () {
            $days    = $this->dayRange(-self::BALISE_READINGS_DAYS_PAST, 0);
            $balises = Balise::where('active', true)->orderBy('source')->orderBy('name')->get();

            $payload = [
                'days'   => $days,
                'groups' => [],
            ];

            if ($balises->isEmpty() || ! DB::getSchemaBuilder()->hasTable('balise_readings_hourly')) {
                return $payload;
            }

            $start = $days[0]->startOfDay();
            $end   = end($days)->endOfDay();

            $countRaw = DB::table('balise_readings_hourly')
                ->whereBetween('hour_at', [$start, $end])
                ->whereIn('balise_id', $balises->pluck('id'))
                ->selectRaw('balise_id, DATE(hour_at) as day, COUNT(DISTINCT hour_at) as n')
                ->groupBy('balise_id', 'day')
                ->get();

            $byBaliseDay = [];
            foreach ($countRaw as $r) {
                $byBaliseDay[$r->balise_id][$r->day] = (int) $r->n;
            }

            // Dernière réception (depuis la table source `balise_readings`
            // si présente, sinon fallback sur l'agrégat horaire).
            $lastByBalise = [];
            if (DB::getSchemaBuilder()->hasTable('balise_readings')) {
                $rawLast = DB::table('balise_readings')
                    ->whereIn('balise_id', $balises->pluck('id'))
                    ->selectRaw('balise_id, MAX(read_at) as last_at')
                    ->groupBy('balise_id')
                    ->get();
                foreach ($rawLast as $r) {
                    if ($r->last_at) {
                        $lastByBalise[$r->balise_id] = Carbon::parse((string) $r->last_at);
                    }
                }
            }

            $groupsByCode = [];
            foreach ($balises as $balise) {
                $code  = $balise->source ?: 'inconnu';
                $cells = [];
                foreach ($days as $day) {
                    $key = $day->format('Y-m-d');
                    $n   = $byBaliseDay[$balise->id][$key] ?? 0;
                    $cells[] = round(($n / 24) * 100, 1);
                }

                if (! isset($groupsByCode[$code])) {
                    $groupsByCode[$code] = [
                        'source'         => $code,
                        'label'          => $this->sourceLabel($code),
                        'balises_count'  => 0,
                        // Somme des heures reçues par jour (agrégat brut)
                        'hours_received' => array_fill(0, count($days), 0),
                        'balises'        => [],
                    ];
                }

                $groupsByCode[$code]['balises_count']++;
                foreach ($days as $i => $day) {
                    $key = $day->format('Y-m-d');
                    $groupsByCode[$code]['hours_received'][$i] += $byBaliseDay[$balise->id][$key] ?? 0;
                }
                $groupsByCode[$code]['balises'][] = [
                    'balise'          => $balise,
                    'cells'           => $cells,
                    'last_reading_at' => $lastByBalise[$balise->id] ?? null,
                ];
            }

            // Conversion sommes → % d'agrégat par jour.
            $groups = [];
            foreach ($groupsByCode as $g) {
                $expectedPerDay = 24 * $g['balises_count'];
                $aggCells       = [];
                foreach ($g['hours_received'] as $hours) {
                    $aggCells[] = $expectedPerDay > 0
                        ? round(($hours / $expectedPerDay) * 100, 1)
                        : null;
                }
                $groups[] = [
                    'source'          => $g['source'],
                    'label'           => $g['label'],
                    'balises_count'   => $g['balises_count'],
                    'aggregate_cells' => $aggCells,
                    'balises'         => $g['balises'],
                ];
            }

            // Tri stable des groupes par label pour un affichage prévisible.
            usort($groups, fn ($a, $b) => strcmp($a['label'], $b['label']));

            $payload['groups'] = $groups;
            return $payload;
        });
    }

    private function sourceLabel(string $code): string
    {
        return match (strtolower($code)) {
            'pioupiou' => 'PiouPiou',
            'metar'    => 'METAR',
            'ffvl'     => 'FFVL',
            'inconnu'  => 'Source inconnue',
            default    => ucfirst($code),
        };
    }

    /**
     * Génère la suite de dates [now() + offsetStart … now() + offsetEnd] (inclus).
     *
     * @return array<int, CarbonImmutable>
     */
    private function dayRange(int $offsetStart, int $offsetEnd): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $days  = [];
        for ($i = $offsetStart; $i <= $offsetEnd; $i++) {
            $days[] = $today->addDays($i);
        }
        return $days;
    }
}
