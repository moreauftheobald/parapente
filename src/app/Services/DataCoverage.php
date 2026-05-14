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
    private const CACHE_VERSION = 'v2';

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
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.model_freshness');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.site_forecasts');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.balise_forecasts');
        Cache::forget('data_coverage.' . self::CACHE_VERSION . '.balise_readings');
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
        // Cache désactivé temporairement : la persistance file/redis a
        // pollué les entrées avec des Eloquent sérialisés (avant le
        // refactor lightModel) qui se déballent en __PHP_Incomplete_Class
        // → 500 dans Blade. Mieux vaut rebuilder à chaque appel (page
        // admin consultée ponctuellement, build < 100 ms).
        try {
            return $this->inflateModelFreshness($this->buildModelFreshness());
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
        // Cache désactivé temporairement (cf. commentaire sur
        // modelFreshness()). Le paramètre $cacheKey est conservé pour
        // ne pas casser la signature et faciliter la réactivation.
        try {
            return $inflate($build());
        } catch (\Throwable $e) {
            Log::error("DataCoverage::{$cacheKey} build failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $default;
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
}
