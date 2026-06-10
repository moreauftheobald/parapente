<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\BaliseReading;
use App\Models\Forecast;
use App\Models\JobMonitor;
use App\Models\Site;
use App\Models\SiteScore;
use App\Models\WeatherModel;
use App\Services\Admin\SupervisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * BackOffice — Logs et monitoring.
 *
 * Deux écrans :
 *   - `jobs()`  : explorateur des exécutions de jobs (`job_monitors`,
 *     30 j) filtrable par catégorie / job / statut — y compris les runs
 *     du sidecar journalisés par WatchScoringTableJob.
 *   - `index()` : logs Laravel bruts — tail du fichier
 *     storage/logs/laravel.log filtré par niveau et recherche texte
 *     (~500 derniers Ko), + volumétrie DB.
 */
class LogController extends Controller
{
    /** Taille max lue à la fin du fichier de log */
    private const TAIL_BYTES = 500_000;

    /** Nb max d'entrées affichées */
    private const MAX_ENTRIES = 200;

    /** Libellés des catégories de jobs (colonne job_monitors.job_group). */
    public const GROUP_LABELS = [
        'sites'     => 'Sites',
        'balises'   => 'Balises',
        'stations'  => 'Stations météo',
        'fiabilite' => 'Fiabilité',
        'sidecar'   => 'Sidecar',
        'systeme'   => 'Système',
    ];

    /**
     * Explorateur des exécutions de jobs, par catégorie / job / statut.
     */
    public function jobs(Request $request): View
    {
        $group  = $request->input('group');
        $job    = $request->input('job');
        $status = $request->input('status');

        $query = JobMonitor::query()->orderByDesc('started_at');
        if ($group)  $query->where('job_group', $group);
        if ($job)    $query->where('job_class', $job);
        if ($status) $query->where('status', $status);

        // Listes des filtres dérivées des données réelles (pas de registre
        // en dur : tout job qui trace apparaît automatiquement).
        $groups  = JobMonitor::query()->distinct()->orderBy('job_group')->pluck('job_group');
        $classes = JobMonitor::query()->distinct()->orderBy('job_class')->pluck('job_class');

        // Labels lisibles : registre Supervision pour les jobs connus,
        // class_basename sinon.
        $labels = [];
        foreach (SupervisionService::JOBS as $class => [$label]) {
            $labels[$class] = $label;
        }
        $labels[\App\Jobs\WatchScoringTableJob::SIDECAR_JOB_CLASS] = 'Run sidecar (consensus + scoring)';

        return view('admin.logs.jobs', [
            'runs'    => $query->paginate(50)->withQueryString(),
            'group'   => $group,
            'job'     => $job,
            'status'  => $status,
            'groups'  => $groups,
            'classes' => $classes,
            'labels'  => $labels,
        ]);
    }

    /**
     * Historique des runs du sidecar (panneau Alpine alimenté par les
     * proxys JSON /admin/meteo/sidecar/runs-*).
     */
    public function sidecar(): View
    {
        return view('admin.logs.sidecar', [
            'runsRecentEndpoint' => route('admin.meteo.sidecar.runs-recent', [], false),
            'runsStatsEndpoint'  => route('admin.meteo.sidecar.runs-stats', [], false),
        ]);
    }

    public function index(Request $request): View
    {
        $level  = $request->input('level');
        $search = trim((string) $request->input('search', ''));

        $entries = $this->readRecentLogEntries($level, $search);

        return view('admin.logs.index', [
            'level'       => $level,
            'search'      => $search,
            'entries'     => $entries,
            'logSize'     => $this->logFileSize(),
            'logPath'     => storage_path('logs/laravel.log'),
            'counts'      => [
                'sites'                    => Site::count(),
                'sites_active'             => Site::where('active', true)->count(),
                'balises'                  => Balise::count(),
                'balises_active'           => Balise::where('active', true)->count(),
                'balise_readings'          => BaliseReading::count(),
                'forecasts'                => Forecast::count(),
                'site_scores'              => SiteScore::onActiveBuffer()->count(),
                'weather_models'           => WeatherModel::count(),
                'weather_models_active'    => WeatherModel::where('active', true)->count(),
                'forecast_archive_balises' => DB::getSchemaBuilder()->hasTable('forecast_archive_balises')
                    ? DB::table('forecast_archive_balises')->count() : 0,
            ],
        ]);
    }

    /**
     * Lit les ~500 derniers Ko du fichier de log et parse les entrées.
     * Format Laravel standard : "[YYYY-MM-DD HH:MM:SS] env.LEVEL: message".
     * Les lignes de continuation (stack trace) sont attachées à
     * l'entrée précédente.
     *
     * @return array<int, array{time:string, level:string, message:string}>
     */
    private function readRecentLogEntries(?string $levelFilter = null, string $search = ''): array
    {
        $logPath = storage_path('logs/laravel.log');
        if (! is_readable($logPath)) {
            return [];
        }

        $size      = filesize($logPath);
        $chunkSize = min($size, self::TAIL_BYTES);
        $f         = fopen($logPath, 'rb');
        if ($f === false) return [];

        if ($size > $chunkSize) {
            fseek($f, $size - $chunkSize);
            fgets($f); // skip ligne potentiellement tronquée
        }

        $entries = [];
        $current = null;
        while (($line = fgets($f)) !== false) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [a-z0-9_]+\.([A-Z]+): (.*)$/', $line, $m)) {
                if ($current) $entries[] = $current;
                $current = [
                    'time'    => $m[1],
                    'level'   => $m[2],
                    'message' => rtrim($m[3]),
                ];
            } elseif ($current !== null) {
                $current['message'] .= "\n" . rtrim($line);
            }
        }
        if ($current) $entries[] = $current;
        fclose($f);

        // Filtres
        if ($levelFilter) {
            $entries = array_filter($entries, fn ($e) => $e['level'] === strtoupper($levelFilter));
        }
        if ($search !== '') {
            $needle  = strtolower($search);
            $entries = array_filter($entries, fn ($e) => str_contains(strtolower($e['message']), $needle));
        }

        // Plus récent en haut, limite
        return array_slice(array_reverse(array_values($entries)), 0, self::MAX_ENTRIES);
    }

    private function logFileSize(): ?int
    {
        $logPath = storage_path('logs/laravel.log');
        return is_readable($logPath) ? filesize($logPath) : null;
    }
}
