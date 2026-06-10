<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
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
 * stations météo avec capteur vent, pour comparaison ultérieure avec
 * les observations réelles.
 *
 * Traite chunk par chunk (40 stations) avec upsert immédiat pour
 * maîtriser la consommation mémoire (~1500 stations × 13 modèles).
 */
class FetchStationForecastsJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $timeout = 900;
    public int $tries   = 2;

    private const MAX_HORIZON_HOURS = 72;
    private const CHUNK_SIZE        = 20;

    protected function monitorGroup(): string
    {
        return 'stations';
    }

    public function handle(OpenMeteoApi $openMeteo): void
    {
        $this->trackStart();
        ini_set('memory_limit', '1G');
        $points = DB::table('weather_stations')
            ->where('active', true)
            ->where('has_wind_sensor', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('id', 'latitude', 'longitude')
            ->get()
            ->map(fn ($s) => [
                'id'  => $s->id,
                'lat' => (float) $s->latitude,
                'lng' => (float) $s->longitude,
            ])
            ->all();

        if (empty($points)) {
            $this->trackSuccess('Aucune station avec capteur vent');
            Log::info('FetchStationForecastsJob: no active wind-sensor station');
            return;
        }

        $omApiIds = WeatherApi::whereIn('code', ['openmeteo', 'openmeteo_public'])
            ->where('active', true)
            ->pluck('id', 'id');

        if ($omApiIds->isEmpty()) {
            $this->trackSuccess('Aucune API Open-Meteo active');
            Log::warning('FetchStationForecastsJob: no active Open-Meteo API');
            return;
        }

        $models = WeatherModel::with('api')
            ->where('active', true)
            ->where('max_horizon_h', '>=', 24)
            ->whereIn('weather_api_id', $omApiIds->keys())
            ->get();

        $fetchedAt = Carbon::now();
        $fetchedAtStr = $fetchedAt->format('Y-m-d H:i:s');
        $totalUpsert = 0;
        $pointChunks = array_chunk($points, self::CHUNK_SIZE);

        Log::info('FetchStationForecastsJob: starting', [
            'stations' => count($points),
            'chunks'   => count($pointChunks),
            'models'   => $models->count(),
        ]);

        foreach ($models as $model) {
            $modelStart = microtime(true);
            $openMeteo->setConfig($model->api);
            $modelRows = 0;

            foreach ($pointChunks as $chunk) {
                $batch = $openMeteo->fetchStationBatchChunk($chunk, $model);
                if (empty($batch)) {
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

                        $rows[] = [
                            'weather_station_id' => $stationId,
                            'weather_model_id'   => $model->id,
                            'target_at'          => $targetAt->format('Y-m-d H:i:s'),
                            'fetched_at'         => $fetchedAtStr,
                            'horizon_bucket'     => $this->bucketFor($horizonH),
                            'wind_direction'     => $values['wind_direction'],
                            'wind_speed_avg'     => $values['wind_speed_avg'],
                            'wind_speed_max'     => $values['wind_speed_max'],
                            'temperature'        => $values['temperature'],
                            'dew_point'          => $values['dew_point'],
                            'humidity'           => $values['humidity'],
                            'precipitation'      => $values['precipitation'],
                            'pressure_hpa'       => $values['pressure_hpa'],
                            'cloud_cover'        => $values['cloud_cover'],
                            'created_at'         => $fetchedAtStr,
                            'updated_at'         => $fetchedAtStr,
                        ];
                    }
                }

                if (! empty($rows)) {
                    foreach (array_chunk($rows, 500) as $upsertChunk) {
                        DB::table('forecast_archive_stations')->upsert(
                            $upsertChunk,
                            ['weather_station_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                            [
                                'fetched_at',
                                'wind_direction', 'wind_speed_avg', 'wind_speed_max',
                                'temperature', 'dew_point', 'humidity',
                                'precipitation', 'pressure_hpa', 'cloud_cover',
                                'updated_at',
                            ]
                        );
                        $modelRows += count($upsertChunk);
                    }
                }

                unset($batch, $rows);
            }

            $elapsed = round(microtime(true) - $modelStart, 1);
            Log::info("FetchStationForecastsJob: {$model->code}", [
                'rows'    => $modelRows,
                'elapsed' => "{$elapsed}s",
            ]);

            WeatherFetchLog::create([
                'weather_model_id' => $model->id,
                'scope'            => 'station',
                'fetched_at'       => $fetchedAt,
                'rows_upserted'    => $modelRows,
                'provider_run_at'  => null,
            ]);

            $totalUpsert += $modelRows;
        }

        // Consensus : même source que les sites (sidecar). Archivé aux coords
        // des stations comme le modèle qui_vole_consensus.
        $totalUpsert += $this->archiveConsensus($openMeteo, $pointChunks, $fetchedAt, $fetchedAtStr);

        $this->trackSuccess("{$totalUpsert} rows, " . count($points) . " stations, {$models->count()} modèles (+ consensus)", ['rows_upserted' => $totalUpsert, 'stations' => count($points), 'models' => $models->count()]);

        Log::info('FetchStationForecastsJob completed', [
            'stations'      => count($points),
            'models'        => $models->count(),
            'rows_upserted' => $totalUpsert,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }

    /**
     * Fetch le consensus aux coords des stations (sidecar, modèle
     * qui_vole_consensus) et l'archive comme un modèle. Résilient.
     *
     * @param array<int,array<int,array{id:int,lat:float,lng:float}>> $pointChunks
     */
    private function archiveConsensus(OpenMeteoApi $openMeteo, array $pointChunks, Carbon $fetchedAt, string $fetchedAtStr): int
    {
        $consensus = WeatherModel::with('api')->where('code', WeatherModel::CONSENSUS_CODE)->first();
        if (! $consensus || ! $consensus->api) {
            Log::warning('FetchStationForecastsJob: modèle/API consensus absent — archivage consensus ignoré');
            return 0;
        }

        $openMeteo->setConfig($consensus->api);
        $upserted = 0;

        foreach ($pointChunks as $chunk) {
            $batch = $openMeteo->fetchStationBatchChunk($chunk, $consensus);
            if (empty($batch)) {
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
                    $rows[] = [
                        'weather_station_id' => $stationId,
                        'weather_model_id'   => $consensus->id,
                        'target_at'          => $targetAt->format('Y-m-d H:i:s'),
                        'fetched_at'         => $fetchedAtStr,
                        'horizon_bucket'     => $this->bucketFor($horizonH),
                        'wind_direction'     => $values['wind_direction'],
                        'wind_speed_avg'     => $values['wind_speed_avg'],
                        'wind_speed_max'     => $values['wind_speed_max'],
                        'temperature'        => $values['temperature'],
                        'dew_point'          => $values['dew_point'],
                        'humidity'           => $values['humidity'],
                        'precipitation'      => $values['precipitation'],
                        'pressure_hpa'       => $values['pressure_hpa'],
                        'cloud_cover'        => $values['cloud_cover'],
                        'created_at'         => $fetchedAtStr,
                        'updated_at'         => $fetchedAtStr,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $upsertChunk) {
                DB::table('forecast_archive_stations')->upsert(
                    $upsertChunk,
                    ['weather_station_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                    ['fetched_at', 'wind_direction', 'wind_speed_avg', 'wind_speed_max', 'temperature', 'dew_point', 'humidity', 'precipitation', 'pressure_hpa', 'cloud_cover', 'updated_at']
                );
                $upserted += count($upsertChunk);
            }
            unset($batch, $rows);
        }

        WeatherFetchLog::create([
            'weather_model_id' => $consensus->id,
            'scope'            => 'station',
            'fetched_at'       => $fetchedAt,
            'rows_upserted'    => $upserted,
            'provider_run_at'  => null,
        ]);

        return $upserted;
    }

    private function bucketFor(int $horizonH): string
    {
        if ($horizonH <= 6)  return 'nowcast';
        if ($horizonH <= 24) return 'same_day';
        if ($horizonH <= 48) return 'j_plus_1';
        return 'j_plus_2';
    }
}
