<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\BaliseReading;
use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteScore;
use App\Models\WeatherModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * BackOffice — Logs et monitoring.
 *
 * Trois sections :
 *   1. Compteurs DB (volumétrie des grandes tables)
 *   2. Logs Laravel : tail du fichier storage/logs/laravel.log filtré
 *      par niveau et recherche texte. Lecture limitée aux derniers
 *      ~500 Ko pour rester rapide même si le fichier grossit.
 *   3. Dernières exécutions des jobs (extrait depuis les logs).
 */
class LogController extends Controller
{
    /** Taille max lue à la fin du fichier de log */
    private const TAIL_BYTES = 500_000;

    /** Nb max d'entrées affichées */
    private const MAX_ENTRIES = 200;

    public function index(Request $request): View
    {
        $level  = $request->input('level');
        $search = trim((string) $request->input('search', ''));

        $entries  = $this->readRecentLogEntries($level, $search);
        $jobStats = $this->extractJobStats($entries);

        return view('admin.logs.index', [
            'level'       => $level,
            'search'      => $search,
            'entries'     => $entries,
            'jobStats'    => $jobStats,
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

    /**
     * Extrait des entrées les dernières exécutions des jobs connus.
     * Les jobs loguent un message "JobNameJob completed" + JSON contexte.
     *
     * @param  array $entries
     * @return array<string, array{time:string, message:string}>  job_name => …
     */
    private function extractJobStats(array $entries): array
    {
        $jobs = [
            'FetchPiouPiouReadingsJob'  => null,
            'FetchMetarReadingsJob'     => null,
            'FetchBaliseForecastsJob'   => null,
            'PurgeOldForecastsJob'      => null,
        ];
        foreach ($entries as $e) {
            foreach (array_keys($jobs) as $jobName) {
                if ($jobs[$jobName] === null && str_contains($e['message'], $jobName . ' completed')) {
                    $jobs[$jobName] = $e;
                }
            }
        }
        return $jobs;
    }

    private function logFileSize(): ?int
    {
        $logPath = storage_path('logs/laravel.log');
        return is_readable($logPath) ? filesize($logPath) : null;
    }
}
