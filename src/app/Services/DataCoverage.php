<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Balise;
use App\Models\Site;
use App\Models\WeatherModel;
use App\Models\WeatherStation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * Version du schéma des payloads en cache. À incrémenter à chaque
     * fois que la forme des données stockées change — sinon une vieille
     * entrée (avec p.ex. des Eloquent sérialisés) crashe le template
     * Blade au déballage (`__PHP_Incomplete_Class`).
     */
    private const CACHE_VERSION = 'v3';

    /** Périmètre des prévisions sites : 5 jours futurs (J → J+4) */
    private const SITE_FORECAST_DAYS_FUTURE = 5;

    /** Périmètre des prévisions balises : J-7 → J+5 */
    private const BALISE_FORECAST_DAYS_PAST   = 7;
    private const BALISE_FORECAST_DAYS_FUTURE = 6; // J inclus + 5 → 6 colonnes futur

    /** Périmètre des relevés balises : J-7 → J */
    private const BALISE_READINGS_DAYS_PAST = 7;

    /** Périmètre des prévisions stations : J-7 → J+5 */
    private const STATION_FORECAST_DAYS_PAST   = 7;
    private const STATION_FORECAST_DAYS_FUTURE = 6;

    /** Périmètre des relevés stations : J-7 → J */
    private const STATION_READINGS_DAYS_PAST = 7;

    /**
     * Vide les caches calculés. À appeler après un fetch manuel si on
     * veut une mise à jour immédiate de l'écran (non câblé pour l'instant).
     */
    public function flush(): void
    {
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.model_freshness');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.site_forecasts');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.balise_forecasts');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.balise_readings');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.station_forecasts');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.station_readings');
        Cache::forget(self::BARS_KEY_BALISES);
        Cache::forget(self::BARS_KEY_STATIONS);
    }

    /**
     * Section 1 — Fraîcheur des modèles météo.
     *
     * Le cache contient des objets « légers » (stdClass) plutôt que des
     * Eloquent — évite les soucis de désérialisation Redis quand le
     * schéma `weather_models` évolue ou quand un modèle est désactivé
     * entre l'écriture et la lecture du cache.
     *
     * @return array<int, array{
     *     model: \stdClass,
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
        try {
            return $this->inflateModelFreshness(
                Cache::remember(
                    'data_coverage.' . self::CACHE_VERSION . '.model_freshness',
                    self::CACHE_TTL_S,
                    fn () => $this->buildModelFreshness(),
                )
            );
        } catch (\Throwable $e) {
            Log::error('DataCoverage::modelFreshness build failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    private function buildModelFreshness(): array
    {
        $models = WeatherModel::query()->orderBy('name')->get();
        // Si la table n'existe pas encore (déploiement en cours), on
        // renvoie un tableau vide — pas de 500.
        if (! DB::getSchemaBuilder()->hasTable('weather_fetch_log')) {
            return $models->map(fn (WeatherModel $m) => [
                'model'                 => $this->lightModel($m),
                'last_fetch_site'       => null,
                'last_fetch_balise'     => null,
                'last_rows_site'        => null,
                'last_rows_balise'      => null,
                'last_provider_run'     => null,
                'expected_interval_min' => max(1, (int) $m->refresh_frequency_minutes),
                'observed_interval_min' => null,
                'drift_pct'             => null,
            ])->all();
        }

        // Borne la requête : on n'a besoin que des fetches récents
        // pour calculer la cadence observée. Sans borne la table
        // grossit indéfiniment et la lecture explose en mémoire.
        $since = CarbonImmutable::now()->subDays(14);
        $logs = DB::table('weather_fetch_log')
            ->select('weather_model_id', 'scope', 'fetched_at', 'rows_upserted', 'provider_run_at')
            ->where('fetched_at', '>=', $since)
            ->orderByDesc('fetched_at')
            ->limit(2000)
            ->get()
            ->groupBy(fn ($r) => $r->weather_model_id . '|' . $r->scope);

        $rows = [];
        foreach ($models as $model) {
            $siteLogs   = $logs->get($model->id . '|site',   collect());
            $baliseLogs = $logs->get($model->id . '|balise', collect());

            $allLogs = $siteLogs->merge($baliseLogs)
                ->sortByDesc('fetched_at')
                ->values();

            $lastFetchSite   = $siteLogs->first()?->fetched_at;
            $lastFetchBalise = $baliseLogs->first()?->fetched_at;
            $lastRowsSite    = $siteLogs->first()?->rows_upserted;
            $lastRowsBalise  = $baliseLogs->first()?->rows_upserted;
            $lastProviderRun = $allLogs->first()?->provider_run_at;

            $observedMin = null;
            if ($allLogs->count() >= 2) {
                $latest = $this->safeParse((string) $allLogs[0]->fetched_at);
                $prev   = null;
                if ($latest !== null) {
                    foreach ($allLogs->skip(1) as $log) {
                        $candidate = $this->safeParse((string) $log->fetched_at);
                        if ($candidate !== null && $latest->diffInMinutes($candidate, true) >= 1) {
                            $prev = $candidate;
                            break;
                        }
                    }
                }
                if ($prev !== null && $latest !== null) {
                    $observedMin = (int) round($latest->diffInMinutes($prev, true));
                }
            }

            $expectedMin = max(1, (int) $model->refresh_frequency_minutes);
            $driftPct    = $observedMin !== null
                ? round((($observedMin - $expectedMin) / $expectedMin) * 100, 1)
                : null;

            // Stockage en strings/scalars pour éviter toute fragilité
            // de désérialisation Carbon en cache Redis. Le contrôleur
            // re-parse à la lecture (cf. inflateModelFreshness()).
            $rows[] = [
                'model'                 => $this->lightModel($model),
                'last_fetch_site'       => $lastFetchSite ? (string) $lastFetchSite : null,
                'last_fetch_balise'     => $lastFetchBalise ? (string) $lastFetchBalise : null,
                'last_rows_site'        => $lastRowsSite !== null ? (int) $lastRowsSite : null,
                'last_rows_balise'      => $lastRowsBalise !== null ? (int) $lastRowsBalise : null,
                'last_provider_run'     => $lastProviderRun ? (string) $lastProviderRun : null,
                'expected_interval_min' => $expectedMin,
                'observed_interval_min' => $observedMin,
                'drift_pct'             => $driftPct,
            ];
        }

        return $rows;
    }

    /**
     * Carbon::parse safe : null si la string est vide ou invalide.
     */
    private function safeParse(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reconvertit les timestamps stringifiés en Carbon mutable (la vue
     * `data-coverage/index.blade.php` les attend en `?Carbon\Carbon`).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function inflateModelFreshness(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['last_fetch_site', 'last_fetch_balise', 'last_provider_run'] as $key) {
                if (isset($row[$key]) && is_string($row[$key])) {
                    try {
                        $row[$key] = Carbon::parse($row[$key]);
                    } catch (\Throwable) {
                        $row[$key] = null;
                    }
                }
            }
        }
        return $rows;
    }

    /**
     * Section 2 — Couverture des prévisions sites sur J → J+4.
     *
     * Le dénominateur est ajusté par modèle selon `max_horizon_h` : un
     * nowcast à 6 h n'est pas pénalisé sur J+2/J+3, sa cellule est
     * marquée « hors horizon » (cf. expectedHoursForFutureDay()).
     *
     * @return array{
     *     days: array<int, CarbonImmutable>,
     *     rows: array<int, array{model: WeatherModel, cells: array<int, array{pct: ?float, in_horizon: bool, expected_h: int}>}>,
     *     sites_active: int,
     * }
     */
    public function siteForecastCoverage(): array
    {
        return $this->safeSection('data_coverage.' . self::CACHE_VERSION . '.site_forecasts', function () {
            $days = $this->dayRange(0, self::SITE_FORECAST_DAYS_FUTURE - 1);
            $sitesActive = Site::where('active', true)->count();
            $models = WeatherModel::query()->where('active', true)->orderBy('name')->get();

            if ($sitesActive === 0 || $models->isEmpty()) {
                return [
                    'days'         => $this->daysToStrings($days),
                    'rows'         => [],
                    'sites_active' => $sitesActive,
                ];
            }

            $start = $days[0]->startOfDay();
            $end   = end($days)->endOfDay();

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

            $tNow = $this->hoursIntoToday();
            $rows = [];
            foreach ($models as $model) {
                $horizonH = max(1, (int) $model->max_horizon_h);
                $cells = [];
                foreach ($days as $idx => $day) {
                    $expectedH = $this->expectedHoursForFutureDay($horizonH, $idx, $tNow);
                    $n         = $byModelDay[$model->id][$day->format('Y-m-d')] ?? 0;
                    $cells[]   = $this->cellFromCounts($n, $expectedH, $sitesActive);
                }
                $rows[] = [
                    'model' => $this->lightModel($model),
                    'cells' => $cells,
                ];
            }

            return [
                'days'         => $this->daysToStrings($days),
                'rows'         => $rows,
                'sites_active' => $sitesActive,
            ];
        }, fn (array $payload) => $this->inflateDayPayload($payload), default: [
            'days' => [], 'rows' => [], 'sites_active' => 0,
        ]);
    }

    /**
     * Section 3 — Couverture des prévisions balises sur J-7 → J+5.
     *
     * `FetchBaliseForecastsJob` n'archive que les modèles ayant
     * `max_horizon_h >= 24` (filtre métier : pas de fiabilité dynamique
     * en-dessous de 24h). On ne pénalise donc pas un nowcast en lui
     * imposant une couverture nulle ; sa ligne est entièrement marquée
     * « hors archive ».
     *
     * Pour les jours passés : expected = 24 (on aurait dû archiver
     * complètement). Pour les jours futurs : horizon-aware (cf.
     * expectedHoursForFutureDay()).
     *
     * @return array{
     *     days: array<int, CarbonImmutable>,
     *     rows: array<int, array{model: WeatherModel, archived: bool, cells: array<int, array{pct: ?float, in_horizon: bool, expected_h: int}>}>,
     *     balises_active: int,
     * }
     */
    public function baliseForecastCoverage(): array
    {
        return $this->safeSection('data_coverage.' . self::CACHE_VERSION . '.balise_forecasts', function () {
            $days = $this->dayRange(
                -self::BALISE_FORECAST_DAYS_PAST,
                self::BALISE_FORECAST_DAYS_FUTURE - 1
            );
            $balisesActive = Balise::where('active', true)->count();
            $models = WeatherModel::query()->where('active', true)->orderBy('name')->get();

            $payload = [
                'days'           => $this->daysToStrings($days),
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

            // Offset relatif (n=0 = aujourd'hui) aligné sur l'index du
            // tableau $days : days[0] correspond à -BALISE_FORECAST_DAYS_PAST.
            $todayIdx = self::BALISE_FORECAST_DAYS_PAST;
            $tNow     = $this->hoursIntoToday();

            $rows = [];
            foreach ($models as $model) {
                $horizonH = max(1, (int) $model->max_horizon_h);
                $archived = $horizonH >= 24; // filtre de FetchBaliseForecastsJob

                $cells = [];
                foreach ($days as $idx => $day) {
                    if (! $archived) {
                        $cells[] = ['pct' => null, 'in_horizon' => false, 'expected_h' => 0];
                        continue;
                    }

                    $offset    = $idx - $todayIdx;
                    $expectedH = $offset <= 0
                        ? 24
                        : $this->expectedHoursForFutureDay(min($horizonH, 72), $offset, $tNow);

                    $n      = $byModelDay[$model->id][$day->format('Y-m-d')] ?? 0;
                    $cells[] = $this->cellFromCounts($n, $expectedH, $balisesActive);
                }

                $rows[] = [
                    'model'    => $this->lightModel($model),
                    'archived' => $archived,
                    'cells'    => $cells,
                ];
            }

            $payload['rows'] = $rows;
            return $payload;
        }, fn (array $payload) => $this->inflateDayPayload($payload), default: [
            'days' => [], 'rows' => [], 'balises_active' => 0,
        ]);
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
        return $this->safeSection('data_coverage.' . self::CACHE_VERSION . '.balise_readings', function () {
            $days    = $this->dayRange(-self::BALISE_READINGS_DAYS_PAST, 0);
            $balises = Balise::where('active', true)->orderBy('source')->orderBy('name')->get();

            $payload = [
                'days'   => $this->daysToStrings($days),
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
                        // Stocké en string : inflaté en Carbon après
                        // lecture du cache (cf. inflateDayPayload()).
                        $lastByBalise[$r->balise_id] = (string) $r->last_at;
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
                    'balise'          => $this->lightBalise($balise),
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
        }, fn (array $payload) => $this->inflateReadingsPayload($payload), default: [
            'days' => [], 'groups' => [],
        ]);
    }

    /**
     * Section 5 — Couverture des prévisions stations météo sur J-7 → J+5.
     * Même logique que baliseForecastCoverage() mais sur forecast_archive_stations.
     */
    public function stationForecastCoverage(): array
    {
        return $this->safeSection('data_coverage.' . self::CACHE_VERSION . '.station_forecasts', function () {
            $days = $this->dayRange(
                -self::STATION_FORECAST_DAYS_PAST,
                self::STATION_FORECAST_DAYS_FUTURE - 1
            );
            $stationsActive = WeatherStation::where('active', true)->count();
            $models = WeatherModel::query()->where('active', true)->orderBy('name')->get();

            $payload = [
                'days'            => $this->daysToStrings($days),
                'rows'            => [],
                'stations_active' => $stationsActive,
            ];

            if (! DB::getSchemaBuilder()->hasTable('forecast_archive_stations')
                || $stationsActive === 0
                || $models->isEmpty()
            ) {
                return $payload;
            }

            $start = $days[0]->startOfDay();
            $end   = end($days)->endOfDay();

            $rowsRaw = DB::table('forecast_archive_stations')
                ->join('weather_stations', 'weather_stations.id', '=', 'forecast_archive_stations.weather_station_id')
                ->where('weather_stations.active', true)
                ->whereBetween('forecast_archive_stations.target_at', [$start, $end])
                ->selectRaw('forecast_archive_stations.weather_model_id as model_id, DATE(forecast_archive_stations.target_at) as day, COUNT(DISTINCT forecast_archive_stations.weather_station_id, forecast_archive_stations.target_at) as n')
                ->groupBy('model_id', 'day')
                ->get();

            $byModelDay = [];
            foreach ($rowsRaw as $r) {
                $byModelDay[$r->model_id][$r->day] = (int) $r->n;
            }

            $todayIdx = self::STATION_FORECAST_DAYS_PAST;
            $tNow     = $this->hoursIntoToday();

            $rows = [];
            foreach ($models as $model) {
                $horizonH = max(1, (int) $model->max_horizon_h);
                $archived = $horizonH >= 24;

                $cells = [];
                foreach ($days as $idx => $day) {
                    if (! $archived) {
                        $cells[] = ['pct' => null, 'in_horizon' => false, 'expected_h' => 0];
                        continue;
                    }

                    $offset    = $idx - $todayIdx;
                    $expectedH = $offset <= 0
                        ? 24
                        : $this->expectedHoursForFutureDay(min($horizonH, 72), $offset, $tNow);

                    $n      = $byModelDay[$model->id][$day->format('Y-m-d')] ?? 0;
                    $cells[] = $this->cellFromCounts($n, $expectedH, $stationsActive);
                }

                $rows[] = [
                    'model'    => $this->lightModel($model),
                    'archived' => $archived,
                    'cells'    => $cells,
                ];
            }

            $payload['rows'] = $rows;
            return $payload;
        }, fn (array $payload) => $this->inflateDayPayload($payload), default: [
            'days' => [], 'rows' => [], 'stations_active' => 0,
        ]);
    }

    /**
     * Section 6 — Couverture des relevés stations météo sur J-7 → J,
     * groupés par réseau (mf / metar / infoclimat).
     */
    public function stationReadingsCoverage(): array
    {
        return $this->safeSection('data_coverage.' . self::CACHE_VERSION . '.station_readings', function () {
            $days     = $this->dayRange(-self::STATION_READINGS_DAYS_PAST, 0);
            $stations = WeatherStation::where('active', true)->orderBy('network')->orderBy('name')->get();

            $payload = [
                'days'   => $this->daysToStrings($days),
                'groups' => [],
            ];

            if ($stations->isEmpty() || ! DB::getSchemaBuilder()->hasTable('weather_station_observations')) {
                return $payload;
            }

            $start = $days[0]->startOfDay();
            $end   = end($days)->endOfDay();

            $countRaw = DB::table('weather_station_observations')
                ->whereBetween('observed_at', [$start, $end])
                ->whereIn('weather_station_id', $stations->pluck('id'))
                ->selectRaw('weather_station_id, DATE(observed_at) as day, COUNT(DISTINCT HOUR(observed_at)) as n')
                ->groupBy('weather_station_id', 'day')
                ->get();

            $byStationDay = [];
            foreach ($countRaw as $r) {
                $byStationDay[$r->weather_station_id][$r->day] = (int) $r->n;
            }

            $lastByStation = [];
            $rawLast = DB::table('weather_station_observations')
                ->whereIn('weather_station_id', $stations->pluck('id'))
                ->selectRaw('weather_station_id, MAX(observed_at) as last_at')
                ->groupBy('weather_station_id')
                ->get();
            foreach ($rawLast as $r) {
                if ($r->last_at) {
                    $lastByStation[$r->weather_station_id] = (string) $r->last_at;
                }
            }

            $groupsByCode = [];
            foreach ($stations as $station) {
                $code  = $station->network ?: 'inconnu';
                $cells = [];
                foreach ($days as $day) {
                    $key = $day->format('Y-m-d');
                    $n   = $byStationDay[$station->id][$key] ?? 0;
                    $cells[] = round(($n / 24) * 100, 1);
                }

                if (! isset($groupsByCode[$code])) {
                    $groupsByCode[$code] = [
                        'source'          => $code,
                        'label'           => $this->stationNetworkLabel($code),
                        'stations_count'  => 0,
                        'hours_received'  => array_fill(0, count($days), 0),
                        'stations'        => [],
                    ];
                }

                $groupsByCode[$code]['stations_count']++;
                foreach ($days as $i => $day) {
                    $key = $day->format('Y-m-d');
                    $groupsByCode[$code]['hours_received'][$i] += $byStationDay[$station->id][$key] ?? 0;
                }
                $groupsByCode[$code]['stations'][] = [
                    'station'         => $this->lightStation($station),
                    'cells'           => $cells,
                    'last_reading_at' => $lastByStation[$station->id] ?? null,
                ];
            }

            $groups = [];
            foreach ($groupsByCode as $g) {
                $expectedPerDay = 24 * $g['stations_count'];
                $aggCells       = [];
                foreach ($g['hours_received'] as $hours) {
                    $aggCells[] = $expectedPerDay > 0
                        ? round(($hours / $expectedPerDay) * 100, 1)
                        : null;
                }
                $groups[] = [
                    'source'          => $g['source'],
                    'label'           => $g['label'],
                    'stations_count'  => $g['stations_count'],
                    'aggregate_cells' => $aggCells,
                    'stations'        => $g['stations'],
                ];
            }

            usort($groups, fn ($a, $b) => strcmp($a['label'], $b['label']));

            $payload['groups'] = $groups;
            return $payload;
        }, fn (array $payload) => $this->inflateStationReadingsPayload($payload), default: [
            'days' => [], 'groups' => [],
        ]);
    }

    /**
     * Snapshot léger d'un modèle météo — limité aux champs utiles à la
     * vue. Évite de stocker un Eloquent complet dans le cache Redis
     * (risque de désérialisation foireuse si le schéma évolue ou si la
     * classe est modifiée entre l'écriture et la lecture du cache).
     */
    private function lightModel(WeatherModel $m): \stdClass
    {
        return (object) [
            'id'            => (int) $m->id,
            'name'          => (string) $m->name,
            'code'          => (string) $m->code,
            'active'        => (bool) $m->active,
            'max_horizon_h' => (int) ($m->max_horizon_h ?? 0),
        ];
    }

    private function lightBalise(Balise $b): \stdClass
    {
        return (object) [
            'id'     => (int) $b->id,
            'name'   => (string) ($b->name ?? ('#' . $b->id)),
            'source' => (string) ($b->source ?? ''),
        ];
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
     * Heures attendues d'un jour futur compte tenu de l'horizon du modèle.
     *
     * Avec un modèle d'horizon H (en heures) tournant en continu, à
     * l'heure t_now de la journée courante, les heures couvertes d'un
     * jour futur d'offset n (1=demain, 2=après-demain…) sont
     * cumulativement [0, t_now + H - n*24] (bornées dans [0, 24]).
     *
     * Pour le jour courant (n=0), on retourne 24 — l'accumulation de
     * fetches successifs au cours de la journée remplit tout le jour
     * dès lors que H ≥ 1 h et que le système tourne depuis ≥ 24 h.
     *
     * Retourne 0 si le jour est totalement hors de portée du modèle
     * (la cellule sera rendue « hors horizon » dans la vue).
     */
    private function expectedHoursForFutureDay(int $horizonH, int $offset, float $tNow): int
    {
        if ($offset < 0) {
            return 24; // jour passé → on attendait une couverture pleine
        }
        if ($offset === 0) {
            return 24;
        }
        $reach = (int) floor($tNow + $horizonH - $offset * 24);
        return max(0, min(24, $reach));
    }

    /**
     * Convertit un nombre d'heures × balises observées en cellule
     * affichable. Renvoie `in_horizon = false` quand le couple
     * (horizon, jour) ne permet aucune donnée attendue.
     *
     * @return array{pct: ?float, in_horizon: bool, expected_h: int}
     */
    private function cellFromCounts(int $observed, int $expectedHours, int $activeUnits): array
    {
        if ($expectedHours === 0 || $activeUnits === 0) {
            return ['pct' => null, 'in_horizon' => false, 'expected_h' => $expectedHours];
        }
        $denom = $expectedHours * $activeUnits;
        $pct   = round(($observed / $denom) * 100, 1);
        return [
            'pct'        => $pct > 100 ? 100.0 : $pct, // évite >100 en cas de glissement de bord
            'in_horizon' => true,
            'expected_h' => $expectedHours,
        ];
    }

    /**
     * Heure fractionnaire écoulée depuis minuit (0.0 → 24.0).
     */
    private function hoursIntoToday(): float
    {
        $now = CarbonImmutable::now();
        return $now->hour + $now->minute / 60.0;
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

    /**
     * Helper de cache pour les sections 2/3/4 : encapsule
     *   - cache primitive uniquement (pas de Carbon en Redis),
     *   - retry sans cache si la lecture/build pète,
     *   - fallback vers `$default` si tout échoue,
     *   - inflation post-cache via `$inflate`.
     *
     * @param callable():array     $build    Construit la payload (primitives).
     * @param callable(array):array $inflate Reconvertit les strings en Carbon pour la vue.
     * @param array $default Payload retournée si tout échoue.
     */
    private function safeSection(
        string $cacheKey,
        callable $build,
        callable $inflate,
        array $default,
    ): array {
        try {
            return $inflate(Cache::remember($cacheKey, self::CACHE_TTL_S, $build));
        } catch (\Throwable $e) {
            Log::error("DataCoverage::{$cacheKey} build failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Cache corrompu ou build en échec : on retente sans cache
            // avant de rendre la section vide.
            try {
                Cache::forget($cacheKey);
                return $inflate($build());
            } catch (\Throwable) {
                return $default;
            }
        }
    }

    /**
     * Convertit un tableau de CarbonImmutable en tableau de strings
     * `Y-m-d`. Utilisé avant la mise en cache pour éviter de
     * sérialiser des Carbon dans Redis.
     *
     * @param array<int, CarbonImmutable> $days
     * @return array<int, string>
     */
    private function daysToStrings(array $days): array
    {
        return array_map(fn ($d) => $d->format('Y-m-d'), $days);
    }

    /**
     * Reconvertit `days` (strings Y-m-d) en CarbonImmutable pour la
     * vue. Appliqué après lecture du cache pour les sections 2 et 3.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function inflateDayPayload(array $payload): array
    {
        if (isset($payload['days']) && is_array($payload['days'])) {
            $payload['days'] = array_map(
                fn ($d) => is_string($d) ? CarbonImmutable::parse($d) : $d,
                $payload['days']
            );
        }
        return $payload;
    }

    /**
     * Idem `inflateDayPayload()` + reconvertit `last_reading_at`
     * (string) en Carbon mutable dans chaque balise des groupes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function inflateReadingsPayload(array $payload): array
    {
        $payload = $this->inflateDayPayload($payload);

        if (isset($payload['groups']) && is_array($payload['groups'])) {
            foreach ($payload['groups'] as &$group) {
                if (! isset($group['balises']) || ! is_array($group['balises'])) {
                    continue;
                }
                foreach ($group['balises'] as &$b) {
                    if (isset($b['last_reading_at']) && is_string($b['last_reading_at'])) {
                        try {
                            $b['last_reading_at'] = Carbon::parse($b['last_reading_at']);
                        } catch (\Throwable) {
                            $b['last_reading_at'] = null;
                        }
                    }
                }
                unset($b);
            }
            unset($group);
        }

        return $payload;
    }

    private function inflateStationReadingsPayload(array $payload): array
    {
        $payload = $this->inflateDayPayload($payload);

        if (isset($payload['groups']) && is_array($payload['groups'])) {
            foreach ($payload['groups'] as &$group) {
                if (! isset($group['stations']) || ! is_array($group['stations'])) {
                    continue;
                }
                foreach ($group['stations'] as &$s) {
                    if (isset($s['last_reading_at']) && is_string($s['last_reading_at'])) {
                        try {
                            $s['last_reading_at'] = Carbon::parse($s['last_reading_at']);
                        } catch (\Throwable) {
                            $s['last_reading_at'] = null;
                        }
                    }
                }
                unset($s);
            }
            unset($group);
        }

        return $payload;
    }

    private function lightStation(WeatherStation $s): \stdClass
    {
        return (object) [
            'id'      => (int) $s->id,
            'name'    => (string) ($s->name ?? ('#' . $s->id)),
            'network' => (string) ($s->network ?? ''),
        ];
    }

    private function stationNetworkLabel(string $code): string
    {
        return match (strtolower($code)) {
            'mf'         => 'Météo-France',
            'metar'      => 'METAR',
            'infoclimat' => 'Infoclimat',
            'inconnu'    => 'Réseau inconnu',
            default      => ucfirst($code),
        };
    }

    // ════════════════════════════════════════════════════════════
    //  Barres de rétention / profondeur (bandeau « coup d'œil »)
    //  Cf. FF_admin_redesign.md — chaque flux = une barre de N jours
    //  (rétention + marge), segment = jour, couleur = présence
    //  (vert : ≥ SEG_HOURS_FULL h ET ≥ SEG_UNITS_FULL_PCT % d'unités ;
    //   gris : aucune donnée ; orange : partiel). Cache paresseux 30 min,
    //  invalidé par les jobs via forgetBars().
    // ════════════════════════════════════════════════════════════

    private const RETENTION_MARGIN_DAYS = 3;
    private const SEG_HOURS_FULL        = 22;
    private const SEG_UNITS_FULL_PCT    = 90;
    private const BARS_CACHE_TTL_S      = 1800;

    public const BARS_KEY_BALISES  = 'data_coverage.' . self::CACHE_VERSION . '.balise_retention_bars';
    public const BARS_KEY_STATIONS = 'data_coverage.' . self::CACHE_VERSION . '.station_retention_bars';

    /**
     * Invalide le cache des barres d'un scope ('balises' | 'stations').
     * Appelé par les jobs de collecte / agrégation / purge concernés
     * (forget seulement — le recalcul est lazy au prochain affichage).
     */
    public static function forgetBars(string $scope): void
    {
        Cache::forget($scope === 'stations' ? self::BARS_KEY_STATIONS : self::BARS_KEY_BALISES);
    }

    /** @return array<int, array<string, mixed>> 3 barres (archive / horaire / brut) */
    public function baliseRetentionBars(): array
    {
        return $this->cachedBars(self::BARS_KEY_BALISES, function () {
            $active = Balise::where('active', true)->count();
            return [
                $this->buildRetentionBar('Archives prévisions', 'archive', 30,
                    'forecast_archive_balises', 'target_at', 'balise_id', $active, 'model', slotted: true),
                $this->buildRetentionBar('Agrégat horaire', 'hourly', 30,
                    'balise_readings_hourly', 'hour_at', 'balise_id', $active, 'balise_source', slotted: true),
                $this->buildRetentionBar('Relevés bruts', 'raw', 7,
                    'balise_readings', 'read_at', 'balise_id', $active, 'balise_source', slotted: false),
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function stationRetentionBars(): array
    {
        return $this->cachedBars(self::BARS_KEY_STATIONS, function () {
            $active = WeatherStation::where('active', true)->count();
            return [
                $this->buildRetentionBar('Archives prévisions', 'archive', 30,
                    'forecast_archive_stations', 'target_at', 'weather_station_id', $active, 'model', slotted: true),
                $this->buildRetentionBar('Agrégat horaire', 'hourly', 30,
                    'weather_station_observations_hourly', 'hour_at', 'weather_station_id', $active, 'station_network', slotted: true),
                $this->buildRetentionBar('Relevés bruts', 'raw', 7,
                    'weather_station_observations', 'observed_at', 'weather_station_id', $active, 'station_network', slotted: false),
            ];
        });
    }

    /**
     * Cache paresseux + inflation post-cache. Payload caché = primitives
     * uniquement (last_at en string) ; on reconvertit en Carbon à la
     * lecture pour la vue. Sur erreur : rebuild sans cache, puis [].
     *
     * @param callable():array<int,array<string,mixed>> $build
     * @return array<int, array<string, mixed>>
     */
    private function cachedBars(string $key, callable $build): array
    {
        try {
            return $this->inflateBars(Cache::remember($key, self::BARS_CACHE_TTL_S, $build));
        } catch (\Throwable $e) {
            Log::error('DataCoverage::cachedBars failed', ['key' => $key, 'error' => $e->getMessage()]);
            try {
                return $this->inflateBars($build());
            } catch (\Throwable) {
                return [];
            }
        }
    }

    /**
     * Construit une barre de rétention pour un flux donné.
     *
     * @param string $breakdown 'model' | 'balise_source' | 'station_network'
     * @return array<string, mixed>
     */
    private function buildRetentionBar(
        string $label,
        string $key,
        int $retentionDays,
        string $table,
        string $dateCol,
        string $unitCol,
        int $activeUnits,
        string $breakdown,
        bool $slotted = false,
    ): array {
        $window = $retentionDays + self::RETENTION_MARGIN_DAYS;
        $today  = CarbonImmutable::now()->startOfDay();
        $start  = $today->subDays($window - 1);

        $bar = [
            'key'            => $key,
            'label'          => $label,
            'retention_days' => $retentionDays,
            'window_days'    => $window,
            'active_units'   => $activeUnits,
            'sampled'        => $slotted,
            'segments'       => [],
            'breakdown'      => [],
            'oldest_offset'  => null,
            'oldest_date'    => null,
            'last_at'        => null,
            'verdict'        => ['empty' => true, 'has_hole' => false, 'purge_late' => false, 'complete' => false],
        ];

        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return $bar;
        }

        $startStr = $start->format('Y-m-d H:i:s');

        $byDay = $slotted
            ? $this->slottedDayStats($table, $dateCol, $unitCol, $today, $window)
            : $this->genericDayStats($table, $dateCol, $unitCol, $startStr);

        // MIN/MAX sur toute la table (hors fenêtre) → détecte le débordement
        // de purge et donne la dernière réception. Index sur la colonne date
        // → coût négligeable.
        $bounds = DB::table($table)->selectRaw("MIN($dateCol) mn, MAX($dateCol) mx")->first();
        $oldest = ($bounds && $bounds->mn) ? CarbonImmutable::parse($bounds->mn)->startOfDay() : null;

        $segments     = $this->daysToSegments($today, $window, $retentionDays, $byDay, $activeUnits);
        $oldestOffset = $oldest ? (int) round(($oldest->timestamp - $today->timestamp) / 86400) : null;

        // Trou = un jour passé DANS la rétention (hors aujourd'hui, hors marge)
        // qui n'est pas plein.
        $hasHole = false;
        foreach ($segments as $s) {
            if (! $s['is_today'] && ! $s['beyond'] && $s['offset'] <= -1 && $s['state'] !== 'green') {
                $hasHole = true;
                break;
            }
        }

        // Purge en retard : la plus ancienne donnée est plus vieille que la
        // rétention + 1 jour de grâce (timing du job de purge à 03h00).
        $purgeLate = $oldestOffset !== null && $oldestOffset <= -($retentionDays + 1);

        $bar['segments']      = $segments;
        $bar['breakdown']     = $this->buildBreakdown($breakdown, $table, $dateCol, $unitCol, $startStr, $today, $window, $retentionDays, $activeUnits, $slotted);
        $bar['oldest_offset'] = $oldestOffset;
        $bar['oldest_date']   = $oldest?->format('Y-m-d');
        $bar['last_at']       = ($bounds && $bounds->mx) ? (string) $bounds->mx : null;
        $bar['verdict']       = [
            'empty'      => $activeUnits === 0,
            'has_hole'   => $hasHole,
            'purge_late' => $purgeLate,
            'complete'   => $activeUnits > 0 && ! $hasHole,
        ];

        return $bar;
    }

    /**
     * Stats journalières d'une table à créneaux horaires EXACTS
     * (`target_at` / `hour_at` tombent pile sur l'heure) — le cas des
     * archives de prévisions et des agrégats horaires, dont la
     * volumétrie (plusieurs millions de lignes pour les archives à
     * cause de la dimension bucket) interdit un GROUP BY plein.
     *
     * Deux requêtes en accès index pur :
     *  1. présence horaire : DISTINCT sur la liste exhaustive des
     *     créneaux de la fenêtre (window × 24 timestamps) — exact ;
     *  2. unités : COUNT(DISTINCT unit) échantillonné sur le créneau
     *     de 12h00 de chaque jour (33 timestamps) — approximation
     *     « état à midi », suffisante pour le verdict de présence.
     *
     * @return array<string, array{h:int,u:int}>
     */
    private function slottedDayStats(string $table, string $dateCol, string $unitCol, CarbonImmutable $today, int $window): array
    {
        $hourSlots = [];
        $noonSlots = [];
        for ($offset = -($window - 1); $offset <= 0; $offset++) {
            $day = $today->addDays($offset);
            for ($h = 0; $h < 24; $h++) {
                $hourSlots[] = $day->setTime($h, 0)->format('Y-m-d H:i:s');
            }
            $noonSlots[] = $day->setTime(12, 0)->format('Y-m-d H:i:s');
        }

        $byDay = [];

        $present = DB::table($table)
            ->whereIn($dateCol, $hourSlots)
            ->distinct()
            ->pluck($dateCol);
        foreach ($present as $ts) {
            $d = substr((string) $ts, 0, 10);
            $byDay[$d]['h'] = ($byDay[$d]['h'] ?? 0) + 1;
            $byDay[$d]['u'] ??= 0;
        }

        $units = DB::table($table)
            ->whereIn($dateCol, $noonSlots)
            ->selectRaw("DATE($dateCol) d, COUNT(DISTINCT $unitCol) u")
            ->groupBy('d')
            ->get();
        foreach ($units as $r) {
            $byDay[$r->d]['h'] ??= 0;
            $byDay[$r->d]['u'] = (int) $r->u;
        }

        return $byDay;
    }

    /**
     * Stats journalières par GROUP BY classique — réservé aux tables
     * brutes (timestamps quelconques) dont la fenêtre est courte
     * (rétention 7 j + marge).
     *
     * @return array<string, array{h:int,u:int}>
     */
    private function genericDayStats(string $table, string $dateCol, string $unitCol, string $startStr): array
    {
        $rows = DB::table($table)
            ->where($dateCol, '>=', $startStr)
            ->selectRaw("DATE($dateCol) d, COUNT(DISTINCT HOUR($dateCol)) h, COUNT(DISTINCT $unitCol) u")
            ->groupBy('d')
            ->get();

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r->d] = ['h' => (int) $r->h, 'u' => (int) $r->u];
        }
        return $byDay;
    }

    /**
     * Transforme les stats journalières {Y-m-d => [h,u]} en segments
     * colorés sur la fenêtre [today - (window-1) … today].
     *
     * @param array<string, array{h:int,u:int}> $byDay
     * @return array<int, array<string, mixed>>
     */
    private function daysToSegments(
        CarbonImmutable $today,
        int $window,
        int $retentionDays,
        array $byDay,
        int $activeUnits,
    ): array {
        $segments = [];
        for ($offset = -($window - 1); $offset <= 0; $offset++) {
            $day  = $today->addDays($offset);
            $date = $day->format('Y-m-d');
            $h    = $byDay[$date]['h'] ?? 0;
            $u    = $byDay[$date]['u'] ?? 0;
            $isToday = ($offset === 0);

            $segments[] = [
                'offset'      => $offset,
                'date'        => $date,
                'is_today'    => $isToday,
                'beyond'      => $offset <= -$retentionDays,
                'hours'       => $h,
                'units'       => $u,
                'units_total' => $activeUnits,
                'state'       => $this->segmentState($h, $u, $activeUnits, $isToday),
            ];
        }
        return $segments;
    }

    private function segmentState(int $hours, int $units, int $activeUnits, bool $isToday): string
    {
        if ($isToday) {
            return 'pending';
        }
        if ($hours === 0 || $activeUnits <= 0) {
            return 'gray';
        }
        $pct = ($units / $activeUnits) * 100;
        return ($hours >= self::SEG_HOURS_FULL && $pct >= self::SEG_UNITS_FULL_PCT)
            ? 'green'
            : 'orange';
    }

    /**
     * Détail dépliable : sous-barres par modèle (archives) ou par réseau
     * (relevés / agrégat horaire).
     *
     * @return array<int, array{code:string, label:string, segments:array}>
     */
    private function buildBreakdown(
        string $mode,
        string $table,
        string $dateCol,
        string $unitCol,
        string $startStr,
        CarbonImmutable $today,
        int $window,
        int $retentionDays,
        int $activeUnits,
        bool $slotted = false,
    ): array {
        try {
            return match ($mode) {
                'model' => $this->breakdownByModel($table, $dateCol, $unitCol, $today, $window, $retentionDays, $activeUnits),
                'balise_source' => $this->breakdownByNetwork($table, $dateCol, $unitCol, $startStr, $today, $window, $retentionDays, 'balises', 'source', fn ($c) => $this->sourceLabel($c), $slotted),
                'station_network' => $this->breakdownByNetwork($table, $dateCol, $unitCol, $startStr, $today, $window, $retentionDays, 'weather_stations', 'network', fn ($c) => $this->stationNetworkLabel($c), $slotted),
                default => [],
            };
        } catch (\Throwable $e) {
            Log::warning('DataCoverage breakdown failed', ['mode' => $mode, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Liste des créneaux 12h00 de la fenêtre (échantillonnage des
     * tables à créneaux exacts — accès index pur via IN).
     *
     * @return array<int, string>
     */
    private function noonSlots(CarbonImmutable $today, int $window): array
    {
        $slots = [];
        for ($offset = -($window - 1); $offset <= 0; $offset++) {
            $slots[] = $today->addDays($offset)->setTime(12, 0)->format('Y-m-d H:i:s');
        }
        return $slots;
    }

    /**
     * Détail par modèle (archives, toujours slotted) : présence + unités
     * échantillonnées au créneau 12h00 de chaque jour. h := 24 si le
     * créneau est présent (le détail fin des heures reste sur la barre
     * principale — exacte).
     *
     * @return array<int, array<string, mixed>>
     */
    private function breakdownByModel(string $table, string $dateCol, string $unitCol, CarbonImmutable $today, int $window, int $retentionDays, int $activeUnits): array
    {
        $rows = DB::table($table)
            ->whereIn($dateCol, $this->noonSlots($today, $window))
            ->selectRaw("weather_model_id mid, DATE($dateCol) d, COUNT(DISTINCT $unitCol) u")
            ->groupBy('mid', 'd')
            ->get();

        $perModel = [];
        foreach ($rows as $r) {
            $u = (int) $r->u;
            $perModel[(int) $r->mid][$r->d] = ['h' => $u > 0 ? 24 : 0, 'u' => $u];
        }

        $models = WeatherModel::query()
            ->where('active', true)
            ->where('max_horizon_h', '>=', 24)
            ->orderBy('name')
            ->get();

        $out = [];
        foreach ($models as $m) {
            $out[] = [
                'code'     => $m->code,
                'label'    => $m->name,
                'segments' => $this->daysToSegments($today, $window, $retentionDays, $perModel[$m->id] ?? [], $activeUnits),
            ];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function breakdownByNetwork(string $table, string $dateCol, string $unitCol, string $startStr, CarbonImmutable $today, int $window, int $retentionDays, string $joinTable, string $netCol, callable $label, bool $slotted): array
    {
        $query = DB::table($table)
            ->join($joinTable, "$joinTable.id", '=', "$table.$unitCol")
            ->where("$joinTable.active", true);

        if ($slotted) {
            // Table à créneaux horaires exacts : échantillon 12h00, h := 24
            // si présent (accès index pur, pas de scan de fenêtre).
            $rows = $query
                ->whereIn("$table.$dateCol", $this->noonSlots($today, $window))
                ->selectRaw("$joinTable.$netCol net, DATE($table.$dateCol) d, COUNT(DISTINCT $table.$unitCol) u")
                ->groupBy('net', 'd')
                ->get()
                ->map(function ($r) {
                    $r->h = ((int) $r->u) > 0 ? 24 : 0;
                    return $r;
                });
        } else {
            $rows = $query
                ->where("$table.$dateCol", '>=', $startStr)
                ->selectRaw("$joinTable.$netCol net, DATE($table.$dateCol) d, COUNT(DISTINCT HOUR($table.$dateCol)) h, COUNT(DISTINCT $table.$unitCol) u")
                ->groupBy('net', 'd')
                ->get();
        }

        $perNet = [];
        foreach ($rows as $r) {
            $net = $r->net ?: 'inconnu';
            $perNet[$net][$r->d] = ['h' => (int) $r->h, 'u' => (int) $r->u];
        }

        $unitsByNet = DB::table($joinTable)
            ->where('active', true)
            ->selectRaw("$netCol net, COUNT(*) c")
            ->groupBy('net')
            ->pluck('c', 'net');

        $out = [];
        foreach ($perNet as $net => $byDay) {
            $out[] = [
                'code'     => (string) $net,
                'label'    => $label((string) $net),
                'segments' => $this->daysToSegments($today, $window, $retentionDays, $byDay, (int) ($unitsByNet[$net] ?? 0)),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * Reconvertit `last_at` (string) en Carbon dans chaque barre, après
     * lecture du cache.
     *
     * @param array<int, array<string, mixed>> $bars
     * @return array<int, array<string, mixed>>
     */
    private function inflateBars(array $bars): array
    {
        foreach ($bars as &$bar) {
            if (isset($bar['last_at']) && is_string($bar['last_at'])) {
                try {
                    $bar['last_at'] = Carbon::parse($bar['last_at']);
                } catch (\Throwable) {
                    $bar['last_at'] = null;
                }
            }
        }
        return $bars;
    }
}
