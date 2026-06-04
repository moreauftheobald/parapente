<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StationApi;
use App\Models\WeatherStation;
use App\Models\WeatherStationObservation;
use App\Services\Map\WeatherStationsBundleCache;
use App\Services\Stations\InfoclimatStationProvider;
use App\Services\Stations\StationProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Polling horaire des observations Infoclimat (réseau StatIC).
 *
 * Surcharge handle() pour insérer TOUTES les observations du jour
 * (pas seulement la dernière) via insertOrIgnore — les stations
 * StatIC remontent des données toutes les ~10 min.
 */
class FetchInfoclimatStationReadingsJob extends FetchWeatherStationReadingsJob
{
    public int $timeout = 300;

    protected function network(): string
    {
        return 'infoclimat';
    }

    protected function provider(): StationProviderInterface
    {
        return app(InfoclimatStationProvider::class);
    }

    public function handle(): void
    {
        $api = StationApi::where('code', $this->network())->first();
        if (! $api || ! $api->active) {
            Log::info(static::class . ': API inactive or missing, skipping');
            return;
        }

        /** @var InfoclimatStationProvider $provider */
        $provider = $this->provider();

        try {
            $latestReadings = $provider->fetchLatestReadings();
        } catch (\Throwable $e) {
            $api->recordError($e->getMessage());
            throw $e;
        }

        if (empty($latestReadings)) {
            Log::warning(static::class . ': empty readings batch');
            return;
        }

        $stations = WeatherStation::query()
            ->where('network', $this->network())
            ->where('active', true)
            ->get(['id', 'external_id'])
            ->keyBy('external_id');

        $allObs = $provider->getAllObservations();
        $inserted = 0;

        foreach ($stations as $extId => $station) {
            $stationObs = $allObs[$extId] ?? [];
            if (empty($stationObs)) continue;

            $rows = [];
            foreach ($stationObs as $r) {
                if (! ($r['observed_at'] ?? null)) continue;

                $rows[] = [
                    'weather_station_id' => $station->id,
                    'observed_at'        => $r['observed_at']->format('Y-m-d H:i:s'),
                    'wind_direction'     => $r['wind_direction'],
                    'wind_speed_avg'     => $r['wind_speed_avg'],
                    'wind_speed_max'     => $r['wind_speed_max'],
                    'temperature'        => $r['temperature'],
                    'humidity'           => $r['humidity'],
                    'pressure_hpa'       => $r['pressure_hpa'] ?? null,
                    'precipitation_mm'   => $r['precipitation_mm'] ?? null,
                    'cloud_cover_pct'    => $r['cloud_cover_pct'] ?? null,
                    'visibility_m'       => $r['visibility_m'] ?? null,
                    'dew_point'          => $r['dew_point'] ?? null,
                    'raw_data'           => isset($r['raw_data']) ? json_encode($r['raw_data']) : null,
                    'created_at'         => now()->format('Y-m-d H:i:s'),
                ];
            }

            if (! empty($rows)) {
                $inserted += WeatherStationObservation::insertOrIgnore($rows);

                $lastObs = end($stationObs);
                $station->update(['last_obs_at' => $lastObs['observed_at']]);
            }
        }

        app(WeatherStationsBundleCache::class)->forgetBundle();

        $deadCutoff = Carbon::now()->subDays(7);
        $deactivated = WeatherStation::query()
            ->where('network', $this->network())
            ->where('active', true)
            ->where('created_at', '<', $deadCutoff)
            ->where(function ($q) use ($deadCutoff) {
                $q->whereNull('last_obs_at')
                  ->orWhere('last_obs_at', '<', $deadCutoff);
            })
            ->update(['active' => false]);

        Log::info(static::class . ' completed', [
            'observations_inserted'  => $inserted,
            'stations_deactivated'   => $deactivated,
            'stations_polled'        => $stations->count(),
        ]);
    }
}
