<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Models\Forecast;
use App\Models\JobMonitor;
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
 * - balise_readings_hourly / weather_station_observations_hourly :
 *   agrégats horaires (vérité-terrain du calcul de fiabilité), conservés
 *   30 jours — alignés sur la fenêtre de fiabilité et la rétention des
 *   archives de prévisions.
 *
 * - balise_readings       : lectures brutes des balises, conservées 7 jours
 *                           (ne servent qu'à l'affichage temps réel et à
 *                           l'agrégation horaire, fenêtre glissante 3 h).
 *
 * - weather_station_observations : observations brutes des stations météo,
 *                                  conservées 7 jours (même logique).
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
    use TracksExecution;

    public int $timeout = 600;
    public int $tries   = 1;

    protected function monitorGroup(): string
    {
        return 'systeme';
    }

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
     * Rétention des agrégats horaires (balise_readings_hourly et
     * weather_station_observations_hourly) : vérité-terrain du calcul
     * de fiabilité sur 30 jours, alignée sur ARCHIVE_RETENTION_DAYS.
     */
    private const HOURLY_RETENTION_DAYS = 30;

    /**
     * Rétention du journal des fetches météo (`weather_fetch_log`).
     * 30 jours suffisent à alimenter l'écran de couverture admin.
     */
    private const FETCH_LOG_RETENTION_DAYS = 30;

    /**
     * Rétention des lectures brutes (balise_readings et
     * weather_station_observations). Elles ne servent qu'à l'affichage
     * temps réel (dernière lecture, historique du jour, graphes J−2) et
     * à l'agrégation horaire (fenêtre glissante 3 h) — l'historique
     * long vit dans les agrégats horaires (30 j).
     */
    private const READINGS_RETENTION_DAYS = 7;

    /**
     * 30 jours : l'explorateur de logs admin (/admin/logs/jobs) sert à
     * revoir l'historique des exécutions — aligné sur les autres
     * historiques longs.
     */
    private const JOB_MONITOR_RETENTION_DAYS = 30;

    /**
     * Taille des lots de DELETE pour les tables volumineuses (lectures
     * brutes) : évite un verrou long sur une suppression massive
     * (notamment au premier run, où des mois d'historique peuvent partir).
     */
    private const PURGE_CHUNK = 10000;

    public function handle(): void
    {
        $this->trackStart();
        $forecastCutoff = Carbon::now()->subDays(self::FORECASTS_RETENTION_DAYS)->startOfDay();
        $archiveCutoff  = Carbon::now()->subDays(self::ARCHIVE_RETENTION_DAYS)->startOfDay();
        $hourlyCutoff   = Carbon::now()->subDays(self::HOURLY_RETENTION_DAYS)->startOfDay();
        $fetchLogCutoff = Carbon::now()->subDays(self::FETCH_LOG_RETENTION_DAYS)->startOfDay();
        $readingsCutoff = Carbon::now()->subDays(self::READINGS_RETENTION_DAYS)->startOfDay();

        $deletedForecasts = Forecast::where('forecast_at', '<', $forecastCutoff)->delete();
        // Les `site_scores_{1,2}` sont DROP/recréées à chaque run par le
        // sidecar (consensus-grid-v2) — pas de purge Laravel à faire.

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

        $deletedStationHourly = 0;
        if (DB::getSchemaBuilder()->hasTable('weather_station_observations_hourly')) {
            $deletedStationHourly = DB::table('weather_station_observations_hourly')
                ->where('hour_at', '<', $hourlyCutoff)
                ->delete();
        }

        $deletedFetchLog = 0;
        if (DB::getSchemaBuilder()->hasTable('weather_fetch_log')) {
            $deletedFetchLog = DB::table('weather_fetch_log')
                ->where('fetched_at', '<', $fetchLogCutoff)
                ->delete();
        }

        // Lectures brutes des balises et observations des stations météo :
        // rétention 30 jours, suppression par lots (tables volumineuses,
        // ~10 min de cadence balises × centaines de capteurs).
        $deletedReadings     = $this->purgeChunked('balise_readings', 'read_at', $readingsCutoff);
        $deletedObservations = $this->purgeChunked('weather_station_observations', 'observed_at', $readingsCutoff);

        $deletedMonitors = JobMonitor::purgeOlderThan(self::JOB_MONITOR_RETENTION_DAYS);

        $this->trackSuccess(
            sprintf(
                'forecasts %d · archives %d/%d · horaires %d/%d · brut %d/%d · fetch_log %d',
                $deletedForecasts,
                $deletedArchive, $deletedArchiveStations,
                $deletedHourly, $deletedStationHourly,
                $deletedReadings, $deletedObservations,
                $deletedFetchLog,
            ),
        );

        Log::info('PurgeOldForecastsJob completed', [
            'forecasts_deleted'         => $deletedForecasts,
            'archive_balises_deleted'   => $deletedArchive,
            'archive_stations_deleted'  => $deletedArchiveStations,
            'balise_hourly_deleted'     => $deletedHourly,
            'station_hourly_deleted'    => $deletedStationHourly,
            'balise_readings_deleted'   => $deletedReadings,
            'station_obs_deleted'       => $deletedObservations,
            'fetch_log_deleted'         => $deletedFetchLog,
            'job_monitors_deleted'      => $deletedMonitors,
            'forecast_cutoff'           => $forecastCutoff->toDateTimeString(),
            'archive_cutoff'            => $archiveCutoff->toDateTimeString(),
            'hourly_cutoff'             => $hourlyCutoff->toDateTimeString(),
            'fetch_log_cutoff'          => $fetchLogCutoff->toDateTimeString(),
            'readings_cutoff'           => $readingsCutoff->toDateTimeString(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }

    /**
     * Supprime par lots les lignes plus anciennes que $cutoff. Tolère
     * l'absence de la table (migration pas encore passée).
     */
    private function purgeChunked(string $table, string $column, Carbon $cutoff): int
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        $total = 0;
        do {
            $deleted = DB::table($table)
                ->where($column, '<', $cutoff)
                ->limit(self::PURGE_CHUNK)
                ->delete();
            $total += $deleted;
        } while ($deleted === self::PURGE_CHUNK);

        return $total;
    }
}
