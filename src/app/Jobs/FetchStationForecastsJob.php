<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WeatherApi;
use App\Models\WeatherFetchLog;
use App\Models\WeatherModel;
use App\Models\WeatherStation;
use App\Services\Weather\Apis\OpenMeteoApi;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archivage horaire des prévisions Open-Meteo aux coordonnées des
 * stations météo actives, pour comparaison ultérieure avec les
 * observations réelles (calcul de fiabilité par modèle / horizon /
 * variable).
 *
 * Même pattern que FetchBaliseForecastsJob mais avec un payload
 * étendu (humidité, précipitations, pression, point de rosée,
 * couverture nuageuse) — les stations pro mesurent davantage de
 * variables que les balises.
 *
 * Schedulé toutes les heures (cf. routes/console.php).
 * Rétention 30 jours, purgée par PurgeOldForecastsJob.
 */
class FetchStationForecastsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 2;

    private const MAX_HORIZON_HOURS = 72;

    public function handle(OpenMeteoApi $openMeteo): void
    {
        $stations = WeatherStation::active()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        if ($stations->isEmpty()) {
            Log::info('FetchStationForecastsJob: no active weather station');
            return;
        }

        $points = $stations->map(fn (WeatherStation $s) => [
            'id'  => $s->id,
            'lat' => (float) $s->latitude,
            'lng' => (float) $s->longitude,
        ])->all();

        $omApiIds = WeatherApi::whereIn('code', ['openmeteo', 'openmeteo_public'])
            ->where('active', true)
            ->pluck('id', 'id');

        if ($omApiIds->isEmpty()) {
            Log::warning('FetchStationForecastsJob: aucune WeatherApi Open-Meteo active.');
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
            $openMeteo->setConfig($model->api);

            $batch = $openMeteo->fetchBatchForStations($points, $model);
            if (empty($batch)) {
                WeatherFetchLog::create([
                    'weather_model_id' => $model->id,
                    'scope'            => 'station',
                    'fetched_at'       => $fetchedAt,
                    'rows_upserted'    => 0,
                    'provider_run_at'  => null,
                ]);
                continue;
            }

            $rows = [];
            foreach ($batch as $stationId => $forecasts) {
                foreach ($forecasts as $datetime => $values) {
                    $targetAt = Carbon::parse($datetime);
                    $horizonH = (int) $fetchedAt->diffInHours($targetAt, false);
                    if ($horizonH < 0 || $horizonH > self::MAX_HORIZON_HOURS) {
                        continue;
                    }
                    $bucket = $this->bucketFor($horizonH);

                    $rows[] = [
                        'weather_station_id' => $stationId,
                        'weather_model_id'   => $model->id,
                        'target_at'          => $targetAt->format('Y-m-d H:i:s'),
                        'fetched_at'         => $fetchedAt->format('Y-m-d H:i:s'),
                        'horizon_bucket'     => $bucket,
                        'wind_direction'     => $values['wind_direction'],
                        'wind_speed_avg'     => $values['wind_speed_avg'],
                        'wind_speed_max'     => $values['wind_speed_max'],
                        'temperature'        => $values['temperature'],
                        'dew_point'          => $values['dew_point'],
                        'humidity'           => $values['humidity'],
                        'precipitation'      => $values['precipitation'],
                        'pressure_hpa'       => $values['pressure_hpa'],
                        'cloud_cover'        => $values['cloud_cover'],
                        'created_at'         => $fetchedAt->format('Y-m-d H:i:s'),
                        'updated_at'         => $fetchedAt->format('Y-m-d H:i:s'),
                    ];
                }
            }

            if (empty($rows)) {
                WeatherFetchLog::create([
                    'weather_model_id' => $model->id,
                    'scope'            => 'station',
                    'fetched_at'       => $fetchedAt,
                    'rows_upserted'    => 0,
                    'provider_run_at'  => null,
                ]);
                continue;
            }

            $modelRows = 0;
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('forecast_archive_stations')->upsert(
                    $chunk,
                    ['weather_station_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                    [
                        'fetched_at',
                        'wind_direction', 'wind_speed_avg', 'wind_speed_max',
                        'temperature', 'dew_point', 'humidity',
                        'precipitation', 'pressure_hpa', 'cloud_cover',
                        'updated_at',
                    ]
                );
                $modelRows   += count($chunk);
                $totalUpsert += count($chunk);
            }

            WeatherFetchLog::create([
                'weather_model_id' => $model->id,
                'scope'            => 'station',
                'fetched_at'       => $fetchedAt,
                'rows_upserted'    => $modelRows,
                'provider_run_at'  => null,
            ]);
        }

        Log::info('FetchStationForecastsJob completed', [
            'stations_count' => $stations->count(),
            'models_count'   => $models->count(),
            'rows_upserted'  => $totalUpsert,
        ]);
    }

    private function bucketFor(int $horizonH): string
    {
        if ($horizonH <= 6)  return 'nowcast';
        if ($horizonH <= 24) return 'same_day';
        if ($horizonH <= 48) return 'j_plus_1';
        return 'j_plus_2';
    }
}
