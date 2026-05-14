<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Balise;
use App\Models\WeatherApi;
use App\Models\WeatherFetchLog;
use App\Models\WeatherModel;
use App\Services\Weather\Apis\OpenMeteoApi;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archivage horaire des prévisions Open-Meteo aux coordonnées des
 * balises actives, pour comparaison ultérieure avec les observations
 * réelles (calcul de fiabilité par modèle / horizon / variable).
 *
 * Pour chaque modèle météo actif :
 *  - 1 seul appel HTTP en mode batch multi-coordonnées (toutes les
 *    balises actives d'un coup) → ~10 calls/heure total au lieu de
 *    ~550 si on bouclait par balise.
 *  - On filtre les prévisions à horizon ≤ 72h (au-delà, la voting
 *    logic ne pondère pas dynamiquement → archive inutile).
 *  - Pour chaque créneau on calcule horizon_bucket (nowcast /
 *    same_day / j_plus_1 / j_plus_2).
 *  - Upsert sur la clé unique (balise, modèle, target_at, bucket).
 *
 * Schedulé toutes les heures (cf. routes/console.php).
 *
 * Volumétrie attendue à régime établi : ~150 000 lignes max à 30j de
 * rétention. Purge gérée par PurgeOldForecastsJob.
 */
class FetchBaliseForecastsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 2;

    /** Au-delà, on n'archive plus (voting logic dynamique limitée à J+2) */
    private const MAX_HORIZON_HOURS = 72;

    public function handle(OpenMeteoApi $openMeteo): void
    {
        $balises = Balise::active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        if ($balises->isEmpty()) {
            Log::info('FetchBaliseForecastsJob: no active balise');
            return;
        }

        $points = $balises->map(fn (Balise $b) => [
            'id'  => $b->id,
            'lat' => (float) $b->latitude,
            'lng' => (float) $b->longitude,
        ])->all();

        // On batch tous les modèles servis par une API Open-Meteo
        // compatible (self-hosted OU API publique — certains modèles
        // comme UKMO Global sont routés sur l'API publique car la
        // self-hosted n'expose pas le vent à 10 m).
        $omApiIds = WeatherApi::whereIn('code', ['openmeteo', 'openmeteo_public'])
            ->where('active', true)
            ->pluck('id', 'id');

        if ($omApiIds->isEmpty()) {
            Log::warning('FetchBaliseForecastsJob: aucune WeatherApi Open-Meteo active.');
            return;
        }

        $models = WeatherModel::with('api')
            ->where('active', true)
            ->where('max_horizon_h', '>=', 24)
            ->whereIn('weather_api_id', $omApiIds->keys())
            ->get();

        $fetchedAt = Carbon::now();
        $totalUpsert = 0;

        foreach ($models as $model) {
            // Reconfigure l'API avant chaque modèle (URL/credentials peuvent
            // changer d'un modèle à l'autre : self-hosted vs public).
            $openMeteo->setConfig($model->api);

            $batch = $openMeteo->fetchBatchForBalises($points, $model);
            if (empty($batch)) {
                // Fetch raté : on trace tout de même pour que l'écran de
                // couverture admin détecte les modèles qui ne répondent
                // plus.
                WeatherFetchLog::create([
                    'weather_model_id' => $model->id,
                    'scope'            => 'balise',
                    'fetched_at'       => $fetchedAt,
                    'rows_upserted'    => 0,
                    'provider_run_at'  => null,
                ]);
                continue;
            }

            $rows = [];
            foreach ($batch as $baliseId => $forecasts) {
                foreach ($forecasts as $datetime => $values) {
                    $targetAt = Carbon::parse($datetime);
                    // diffInHours retourne un float dans Carbon récent → cast int
                    $horizonH = (int) $fetchedAt->diffInHours($targetAt, false);
                    if ($horizonH < 0 || $horizonH > self::MAX_HORIZON_HOURS) {
                        continue;
                    }
                    $bucket = $this->bucketFor($horizonH);

                    $rows[] = [
                        'balise_id'        => $baliseId,
                        'weather_model_id' => $model->id,
                        'target_at'        => $targetAt->format('Y-m-d H:i:s'),
                        'fetched_at'       => $fetchedAt->format('Y-m-d H:i:s'),
                        'horizon_bucket'   => $bucket,
                        'wind_direction'   => $values['wind_direction'],
                        'wind_speed_avg'   => $values['wind_speed_avg'],
                        'wind_speed_min'   => $values['wind_speed_min'],
                        'wind_speed_max'   => $values['wind_speed_max'],
                        'temperature'      => $values['temperature'],
                        'created_at'       => $fetchedAt->format('Y-m-d H:i:s'),
                        'updated_at'       => $fetchedAt->format('Y-m-d H:i:s'),
                    ];
                }
            }

            if (empty($rows)) {
                WeatherFetchLog::create([
                    'weather_model_id' => $model->id,
                    'scope'            => 'balise',
                    'fetched_at'       => $fetchedAt,
                    'rows_upserted'    => 0,
                    'provider_run_at'  => null,
                ]);
                continue;
            }

            $modelRows = 0;
            // Upsert en lots de 500 pour ne pas dépasser le max_allowed_packet
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('forecast_archive_balises')->upsert(
                    $chunk,
                    ['balise_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                    [
                        'fetched_at',
                        'wind_direction', 'wind_speed_avg', 'wind_speed_min',
                        'wind_speed_max', 'temperature',
                        'updated_at',
                    ]
                );
                $modelRows  += count($chunk);
                $totalUpsert += count($chunk);
            }

            WeatherFetchLog::create([
                'weather_model_id' => $model->id,
                'scope'            => 'balise',
                'fetched_at'       => $fetchedAt,
                'rows_upserted'    => $modelRows,
                'provider_run_at'  => null,
            ]);
        }

        Log::info('FetchBaliseForecastsJob completed', [
            'balises_count' => $balises->count(),
            'models_count'  => $models->count(),
            'rows_upserted' => $totalUpsert,
        ]);
    }

    /**
     * Map heure d'horizon → bucket. Cohérent avec le futur calcul
     * d'accuracy (cf. RELIABILITY_SYSTEM.md à venir).
     */
    private function bucketFor(int $horizonH): string
    {
        if ($horizonH <= 6)  return 'nowcast';
        if ($horizonH <= 24) return 'same_day';
        if ($horizonH <= 48) return 'j_plus_1';
        return 'j_plus_2';
    }
}
