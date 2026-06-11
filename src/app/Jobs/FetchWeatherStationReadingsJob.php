<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DataCoverage;
use App\Jobs\Concerns\TracksExecution;
use App\Models\StationApi;
use App\Models\WeatherStation;
use App\Models\WeatherStationObservation;
use App\Services\Stations\StationProviderInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Map\WeatherStationsBundleCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Base abstraite pour les jobs de polling des stations météo (METAR,
 * Météo-France, Infoclimat).
 *
 * Même pattern que FetchBaliseReadingsJob : fetch → dédup → insert →
 * désactivation des stations mortes. Cible weather_stations /
 * weather_station_observations au lieu de balises / balise_readings.
 */
abstract class FetchWeatherStationReadingsJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $tries = 2;

    private const LAST_OBS_CACHE_TTL = 3600;
    private const DEAD_AFTER_DAYS    = 7;

    abstract protected function network(): string;

    abstract protected function provider(): StationProviderInterface;

    protected function warnOnEmptyBatch(): bool
    {
        return true;
    }

    protected function monitorGroup(): string
    {
        return 'stations';
    }

    public function handle(): void
    {
        $this->trackStart();

        $api = StationApi::where('code', $this->network())->first();
        if (! $api || ! $api->active) {
            $this->trackSuccess('API inactive ou manquante');
            Log::info(static::class . ': API inactive or missing, skipping');
            return;
        }

        try {
            $readings = $this->provider()->fetchLatestReadings();
        } catch (\Throwable $e) {
            $api->recordError($e->getMessage());
            throw $e;
        }

        if (empty($readings)) {
            if ($this->warnOnEmptyBatch()) {
                Log::warning(static::class . ': empty readings batch');
            }
            $this->trackSuccess('Lot vide');
            $api->incrementRequestsToday();
            return;
        }

        $stations = WeatherStation::query()
            ->where('network', $this->network())
            ->where('active', true)
            ->get(['id', 'external_id'])
            ->keyBy('external_id');

        $cacheKey        = $this->lastObsCacheKey();
        $latestPerStation = Cache::get($cacheKey);
        if (! is_array($latestPerStation)) {
            $latestPerStation = WeatherStationObservation::whereIn('weather_station_id', $stations->pluck('id'))
                ->select('weather_station_id', DB::raw('MAX(observed_at) as latest'))
                ->groupBy('weather_station_id')
                ->pluck('latest', 'weather_station_id')
                ->all();
        }

        $readingKeys = array_keys($readings);
        $stationKeys = $stations->keys()->all();

        Log::info(static::class . ' matching diagnostic', [
            'readings_count'      => count($readings),
            'stations_count'      => count($stationKeys),
            'sample_reading_keys' => array_slice($readingKeys, 0, 5),
            'sample_station_keys' => array_slice($stationKeys, 0, 5),
            'matched_count'       => count(array_intersect($readingKeys, $stationKeys)),
        ]);

        $inserted = 0;
        $skippedNoReading = 0;
        $skippedDedup = 0;
        $withWind    = [];
        $withoutWind = [];
        foreach ($stations as $extId => $station) {
            $r = $readings[$extId] ?? null;
            if (! $r || ! ($r['observed_at'] ?? null)) {
                $skippedNoReading++;
                continue;
            }

            $previousLatest = $latestPerStation[$station->id] ?? null;
            if ($previousLatest !== null
                && $r['observed_at']->lessThanOrEqualTo(Carbon::parse($previousLatest))) {
                $skippedDedup++;
                continue;
            }

            WeatherStationObservation::create([
                'weather_station_id'  => $station->id,
                'observed_at'         => $r['observed_at'],
                'wind_direction'      => $r['wind_direction'],
                'wind_speed_avg'      => $r['wind_speed_avg'],
                'wind_speed_max'      => $r['wind_speed_max'],
                'wind_speed_max_10m'  => $r['wind_speed_max_10m'] ?? null,
                'wind_direction_max'  => $r['wind_direction_max'] ?? null,
                'wind_direction_gust' => $r['wind_direction_gust'] ?? null,
                'temperature'         => $r['temperature'],
                'temperature_min'     => $r['temperature_min'] ?? null,
                'temperature_max'     => $r['temperature_max'] ?? null,
                'humidity'            => $r['humidity'],
                'humidity_min'        => $r['humidity_min'] ?? null,
                'humidity_max'        => $r['humidity_max'] ?? null,
                'pressure_hpa'        => $r['pressure_hpa'] ?? null,
                'precipitation_mm'    => $r['precipitation_mm'] ?? null,
                'cloud_cover_pct'     => $r['cloud_cover_pct'] ?? null,
                'visibility_m'        => $r['visibility_m'] ?? null,
                'dew_point'           => $r['dew_point'] ?? null,
                'raw_data'            => $r['raw_data'] ?? null,
            ]);
            $inserted++;
            $latestPerStation[$station->id] = $r['observed_at']->toDateTimeString();

            if ($r['wind_speed_avg'] !== null) {
                $withWind[] = $station->id;
            } else {
                $withoutWind[] = $station->id;
            }

            $station->update(['last_obs_at' => $r['observed_at']]);
        }

        Cache::put($cacheKey, $latestPerStation, self::LAST_OBS_CACHE_TTL);

        // Auto-classify has_wind_sensor based on this batch's readings.
        // Promote to true any station that reported wind data (idempotent).
        if ($withWind !== []) {
            WeatherStation::whereIn('id', $withWind)
                ->where(fn ($q) => $q->whereNull('has_wind_sensor')->orWhere('has_wind_sensor', false))
                ->update(['has_wind_sensor' => true]);
        }

        // For stations with no wind in this reading AND never classified
        // (has_wind_sensor IS NULL), set to false. Never downgrade true→false
        // on a single reading — the classify command handles that over multiple days.
        if ($withoutWind !== []) {
            WeatherStation::whereIn('id', $withoutWind)
                ->whereNull('has_wind_sensor')
                ->update(['has_wind_sensor' => false]);
        }

        $deadCutoff  = Carbon::now()->subDays(self::DEAD_AFTER_DAYS);
        $deactivated = WeatherStation::query()
            ->where('network', $this->network())
            ->where('active', true)
            ->where('created_at', '<', $deadCutoff)
            ->where(function ($q) use ($deadCutoff) {
                $q->whereNull('last_obs_at')
                  ->orWhere('last_obs_at', '<', $deadCutoff);
            })
            ->update(['active' => false]);

        app(WeatherStationsBundleCache::class)->forgetBundle();

        $api->incrementRequestsToday();
        $api->recordSuccess();

        DataCoverage::forgetBars('stations');
        $this->trackSuccess("{$inserted} obs insérées, {$deactivated} désactivées", ['inserted' => $inserted, 'deactivated' => $deactivated, 'skipped_dedup' => $skippedDedup, 'polled' => $stations->count()]);

        Log::info(static::class . ' completed', [
            'observations_inserted'  => $inserted,
            'skipped_no_reading'     => $skippedNoReading,
            'skipped_dedup'          => $skippedDedup,
            'stations_deactivated'   => $deactivated,
            'stations_polled'        => $stations->count(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }

    private function lastObsCacheKey(): string
    {
        return 'stations.last_obs_by_station:' . $this->network();
    }
}
