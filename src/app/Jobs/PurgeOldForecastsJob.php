<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Forecast;
use App\Models\JobMonitor;
use App\Models\SiteScore;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purge périodique des données météo obsolètes.
 *
 * - forecasts             : suppression des slots passés (forecast_at < J-1).
 *                           Ces lignes ne sont plus exposées par l'API
 *                           (les scopes ::upcoming filtrent au futur), elles
 *                           grossissent juste la table sans usage.
 *
 * - site_scores           : idem, on purge à J-1.
 *
 * - forecast_archive_balises : conservé 30 jours pour le système de fiabilité
 *                              (fenêtres glissantes 7j primaire + 30j référence).
 *
 * - balise_readings_hourly : agrégat horaire des lectures balises, conservé
 *                            7 jours (fenêtre J-6 → J pour la comparaison
 *                            modèles ↔ balises).
 *
 * - weather_fetch_log     : journal des fetches météo conservé 30 jours
 *                           (sert à l'écran de couverture admin).
 *
 * Ce job est dispatché par le scheduler une fois par jour
 * (cf. routes/console.php).
 */
class PurgeOldForecastsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries   = 1;

    /**
     * Combien de jours dans le passé on garde les forecasts/scores
     * (au-delà, ils sont supprimés). Aujourd'hui exposé en dur ; à
     * basculer en config si on veut le rendre paramétrable.
     */
    private const FORECASTS_RETENTION_DAYS = 1;

    /**
     * Rétention de l'archive balises (utilisée pour le calcul de
     * fiabilité). 30 jours couvre la fenêtre 7j primaire + une marge
     * pour la fenêtre 30j de référence.
     */
    private const ARCHIVE_RETENTION_DAYS = 30;

    /**
     * Rétention de l'agrégat horaire des lectures balises (utilisé pour
     * la comparaison modèles ↔ balises sur la fenêtre J-6 → J).
     */
    private const HOURLY_RETENTION_DAYS = 7;

    /**
     * Rétention du journal des fetches météo (`weather_fetch_log`).
     * 30 jours suffisent à alimenter l'écran de couverture admin.
     */
    private const FETCH_LOG_RETENTION_DAYS = 30;

    private const JOB_MONITOR_RETENTION_DAYS = 7;

    public function handle(): void
    {
        $forecastCutoff = Carbon::now()->subDays(self::FORECASTS_RETENTION_DAYS)->startOfDay();
        $archiveCutoff  = Carbon::now()->subDays(self::ARCHIVE_RETENTION_DAYS)->startOfDay();
        $hourlyCutoff   = Carbon::now()->subDays(self::HOURLY_RETENTION_DAYS)->startOfDay();
        $fetchLogCutoff = Carbon::now()->subDays(self::FETCH_LOG_RETENTION_DAYS)->startOfDay();

        $deletedForecasts = Forecast::where('forecast_at', '<', $forecastCutoff)->delete();
        $deletedScores    = SiteScore::where('forecast_at', '<', $forecastCutoff)->delete();

        // L'archive balises peut ne pas exister si la migration n'a pas
        // encore tourné — on tolère le cas pour pouvoir scheduler ce job
        // dès maintenant sans dépendance dure.
        $deletedArchive = 0;
        if (DB::getSchemaBuilder()->hasTable('forecast_archive_balises')) {
            $deletedArchive = DB::table('forecast_archive_balises')
                ->where('target_at', '<', $archiveCutoff)
                ->delete();
        }

        $deletedArchiveStations = 0;
        if (DB::getSchemaBuilder()->hasTable('forecast_archive_stations')) {
            $deletedArchiveStations = DB::table('forecast_archive_stations')
                ->where('target_at', '<', $archiveCutoff)
                ->delete();
        }

        $deletedHourly = 0;
        if (DB::getSchemaBuilder()->hasTable('balise_readings_hourly')) {
            $deletedHourly = DB::table('balise_readings_hourly')
                ->where('hour_at', '<', $hourlyCutoff)
                ->delete();
        }

        $deletedFetchLog = 0;
        if (DB::getSchemaBuilder()->hasTable('weather_fetch_log')) {
            $deletedFetchLog = DB::table('weather_fetch_log')
                ->where('fetched_at', '<', $fetchLogCutoff)
                ->delete();
        }

        $deletedMonitors = JobMonitor::purgeOlderThan(self::JOB_MONITOR_RETENTION_DAYS);

        Log::info('PurgeOldForecastsJob completed', [
            'forecasts_deleted'         => $deletedForecasts,
            'site_scores_deleted'       => $deletedScores,
            'archive_balises_deleted'   => $deletedArchive,
            'archive_stations_deleted'  => $deletedArchiveStations,
            'balise_hourly_deleted'     => $deletedHourly,
            'fetch_log_deleted'         => $deletedFetchLog,
            'job_monitors_deleted'      => $deletedMonitors,
            'forecast_cutoff'           => $forecastCutoff->toDateTimeString(),
            'archive_cutoff'            => $archiveCutoff->toDateTimeString(),
            'hourly_cutoff'             => $hourlyCutoff->toDateTimeString(),
            'fetch_log_cutoff'          => $fetchLogCutoff->toDateTimeString(),
        ]);
    }
}
