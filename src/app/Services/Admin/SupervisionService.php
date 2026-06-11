<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\Balise;
use App\Models\JobMonitor;
use App\Models\Site;
use App\Models\SiteScore;
use App\Models\StationApi;
use App\Models\User;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use App\Services\Map\ScoringFreshness;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Construit le payload du dashboard Supervision (/admin) : santé du
 * pipeline de jobs, fraîcheur du sidecar, résumé de couverture, état des
 * APIs, incidents récents et volumétrie. Lecture seule — chaque bloc
 * pointe vers l'écran d'action correspondant.
 *
 * Cf. FF_admin_redesign.md (étape 1).
 */
class SupervisionService
{
    /** Blocs lourds (volumétrie, couverture) cachés ; santé en direct. */
    private const COVERAGE_CACHE_KEY  = 'admin.supervision.coverage.v1';
    private const COVERAGE_CACHE_TTL  = 300;
    private const VOLUMETRY_CACHE_KEY = 'admin.supervision.volumetry.v1';
    private const VOLUMETRY_CACHE_TTL = 600;

    /** Marge ajoutée au double de la cadence avant de déclarer un job en retard. */
    private const LATE_GRACE_MINUTES = 5;

    /**
     * Registre des jobs schedulés à surveiller : classe → label, groupe,
     * cadence attendue en minutes (cf. routes/console.php). Tout nouveau
     * job schedulé doit être ajouté ici pour apparaître au dashboard.
     * `WatchScoringTableJob` (cadence 1 min, sans TracksExecution) est
     * couvert par le bloc Sidecar via ScoringFreshness.
     */
    public const JOBS = [
        \App\Jobs\FetchForecastsJob::class                     => ['Prévisions sites (multimodèles)', 'Météo',    60],
        \App\Jobs\FetchPiouPiouReadingsJob::class              => ['Lectures PiouPiou',               'Balises',  10],
        \App\Jobs\FetchWindyReadingsJob::class                 => ['Lectures Windy',                  'Balises',  30],
        \App\Jobs\FetchBaliseForecastsJob::class               => ['Archive prévisions balises',      'Balises',  60],
        \App\Jobs\AggregateBaliseReadingsHourlyJob::class      => ['Agrégation horaire balises',      'Balises',  60],
        \App\Jobs\FetchMfStationReadingsJob::class             => ['Observations Météo-France',       'Stations', 12],
        \App\Jobs\FetchMetarStationReadingsJob::class          => ['Observations METAR',              'Stations', 30],
        \App\Jobs\FetchInfoclimatStationReadingsJob::class     => ['Observations Infoclimat',         'Stations', 60],
        \App\Jobs\FetchStationForecastsJob::class              => ['Archive prévisions stations',     'Stations', 60],
        \App\Jobs\AggregateStationObservationsHourlyJob::class => ['Agrégation horaire stations',     'Stations', 60],
        \App\Jobs\ComputeBaliseConsensusCompareJob::class      => ['Triple-consensus (shadow)',       'Fiabilité', 60],
        \App\Jobs\ComputeModelReliabilityJob::class            => ['Fiabilité des modèles',           'Fiabilité', 1440],
        \App\Jobs\WarmDataCoverageJob::class                   => ['Précalcul couverture données',    'Système',  60],
        \App\Jobs\PurgeOldForecastsJob::class                  => ['Purge données météo',             'Système',  1440],
        \App\Jobs\PurgePageViewsJob::class                     => ['Purge trafic',                    'Système',  1440],
    ];

    public function __construct(private ScoringFreshness $freshness)
    {
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $jobs = $this->jobsHealth();

        return [
            'sidecar'   => $this->freshness->status(),
            'jobs'      => $jobs,
            'jobs_summary' => [
                'ok'      => count(array_filter($jobs, fn ($j) => $j['state'] === 'ok')),
                'late'    => count(array_filter($jobs, fn ($j) => $j['state'] === 'late')),
                'failed'  => count(array_filter($jobs, fn ($j) => $j['state'] === 'failed')),
                'unknown' => count(array_filter($jobs, fn ($j) => $j['state'] === 'unknown')),
            ],
            'coverage'  => Cache::remember(self::COVERAGE_CACHE_KEY, self::COVERAGE_CACHE_TTL, fn () => $this->coverageSummary()),
            'apis'      => $this->apisHealth(),
            'failures'  => $this->recentFailures(),
            'volumetry' => Cache::remember(self::VOLUMETRY_CACHE_KEY, self::VOLUMETRY_CACHE_TTL, fn () => $this->volumetry()),
        ];
    }

    /**
     * État de chaque job du registre : dernier run + dernier succès
     * (job_monitors), comparés à la cadence attendue.
     *
     * @return array<int,array<string,mixed>>
     */
    private function jobsHealth(): array
    {
        $classes = array_keys(self::JOBS);

        $latest = JobMonitor::whereIn('id', function ($q) use ($classes) {
            $q->selectRaw('MAX(id)')->from('job_monitors')
              ->whereIn('job_class', $classes)->groupBy('job_class');
        })->get()->keyBy('job_class');

        $latestSuccess = JobMonitor::whereIn('id', function ($q) use ($classes) {
            $q->selectRaw('MAX(id)')->from('job_monitors')
              ->whereIn('job_class', $classes)->where('status', 'success')
              ->groupBy('job_class');
        })->get()->keyBy('job_class');

        $rows = [];
        foreach (self::JOBS as $class => [$label, $group, $expectedMin]) {
            $last    = $latest->get($class);
            $success = $latestSuccess->get($class);

            $successAgeMin = $success?->finished_at !== null
                ? (int) floor($success->finished_at->diffInMinutes(now(), true))
                : null;

            $state = match (true) {
                $last === null                  => 'unknown', // aucune trace sur la fenêtre job_monitors (30 j)
                $last->status === 'failed'      => 'failed',
                $successAgeMin === null         => 'late',
                $successAgeMin > ($expectedMin * 2 + self::LATE_GRACE_MINUTES) => 'late',
                default                         => 'ok',
            };

            $rows[] = [
                'label'            => $label,
                'group'            => $group,
                'class'            => class_basename($class),
                'expected_minutes' => $expectedMin,
                'state'            => $state,
                'last_success_at'  => $success?->finished_at,
                'last_status'      => $last?->status,
                'duration'         => $success?->durationFormatted() ?? '—',
                'message'          => $last?->status === 'failed'
                    ? ($last->error_message ?? '')
                    : ($success?->message ?? ''),
            ];
        }

        // Les problèmes d'abord, puis par groupe.
        $rank = ['failed' => 0, 'late' => 1, 'unknown' => 2, 'ok' => 3];
        usort($rows, fn ($a, $b) => [$rank[$a['state']], $a['group']] <=> [$rank[$b['state']], $b['group']]);

        return $rows;
    }

    /**
     * Résumé de couverture (requêtes légères — le détail vit dans les
     * onglets « data » des pages de settings, via DataCoverage).
     *
     * @return array<string,array<string,mixed>>
     */
    private function coverageSummary(): array
    {
        $since = now()->subHours(2)->format('Y-m-d H:i:s');

        $sitesActive = Site::where('active', true)->count();
        $sitesScored = SiteScore::onActiveBuffer()
            ->where('forecast_at', '>=', now()->startOfDay())
            ->distinct()->count('site_id');

        $modelsActive  = WeatherModel::where('active', true)
            ->where('code', '!=', WeatherModel::CONSENSUS_CODE)->count();
        $modelsFetched = DB::table('weather_fetch_log')
            ->where('fetched_at', '>=', $since)
            ->where('rows_upserted', '>', 0)
            ->distinct()->count('weather_model_id');

        $balisesActive   = Balise::where('active', true)->count();
        $balisesEmitting = DB::table('balise_readings')
            ->where('read_at', '>=', $since)
            ->distinct()->count('balise_id');

        $stationsActive   = DB::table('weather_stations')->where('active', true)->count();
        $stationsEmitting = DB::table('weather_station_observations')
            ->where('observed_at', '>=', $since)
            ->distinct()->count('weather_station_id');

        $pct = fn (int $n, int $total): ?int => $total > 0 ? (int) round(100 * $n / $total) : null;

        return [
            'sites_scored'      => ['n' => $sitesScored,      'total' => $sitesActive,   'pct' => $pct($sitesScored, $sitesActive),       'label' => 'Sites scorés aujourd\'hui'],
            'models_fetched'    => ['n' => min($modelsFetched, $modelsActive), 'total' => $modelsActive, 'pct' => $pct(min($modelsFetched, $modelsActive), $modelsActive), 'label' => 'Modèles fetchés < 2 h'],
            'balises_emitting'  => ['n' => $balisesEmitting,  'total' => $balisesActive, 'pct' => $pct($balisesEmitting, $balisesActive), 'label' => 'Balises émettrices < 2 h'],
            'stations_emitting' => ['n' => $stationsEmitting, 'total' => $stationsActive, 'pct' => $pct($stationsEmitting, $stationsActive), 'label' => 'Stations émettrices < 2 h'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function apisHealth(): array
    {
        $map = fn ($api, string $kind) => [
            'kind'           => $kind,
            'name'           => $api->name ?? $api->code,
            'active'         => (bool) $api->active,
            'requests_today' => $api->requests_today,
            'daily_quota'    => $api->daily_quota,
            'last_error'     => $api->last_error,
            'last_error_at'  => $api->last_error_at,
        ];

        return [
            ...WeatherApi::orderBy('name')->get()->map(fn ($a) => $map($a, 'Prévisions'))->all(),
            ...StationApi::orderBy('name')->get()->map(fn ($a) => $map($a, 'Stations'))->all(),
        ];
    }

    /** @return \Illuminate\Support\Collection<int,JobMonitor> */
    private function recentFailures()
    {
        return JobMonitor::where('status', 'failed')
            ->where('started_at', '>=', now()->subDay())
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();
    }

    /** @return array<string,int> */
    private function volumetry(): array
    {
        $count = fn (string $table): int => DB::getSchemaBuilder()->hasTable($table)
            ? (int) DB::table($table)->count()
            : 0;

        return [
            'sites_active'                  => Site::where('active', true)->count(),
            'sites_total'                   => Site::count(),
            'balises_total'                 => Balise::count(),
            'stations_total'                => $count('weather_stations'),
            'users_total'                   => User::count(),
            'balise_readings'               => $count('balise_readings'),
            'balise_readings_hourly'        => $count('balise_readings_hourly'),
            'station_observations'          => $count('weather_station_observations'),
            'station_observations_hourly'   => $count('weather_station_observations_hourly'),
            'forecast_archive_balises'      => $count('forecast_archive_balises'),
            'forecast_archive_stations'     => $count('forecast_archive_stations'),
            'forecasts'                     => $count('forecasts'),
        ];
    }
}
