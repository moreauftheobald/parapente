<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeatherStation;
use App\Models\WeatherStationObservation;
use App\Services\Map\WeatherStationsBundleCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeatherStationController extends Controller
{
    public function __construct(
        private readonly WeatherStationsBundleCache $cache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $bbox = $this->parseBbox($request);

        if ($bbox) {
            return response()->json($this->buildForBbox(...$bbox));
        }

        $payload = $this->cache->rememberBundle(fn () => $this->buildBundle());
        return response()->json($payload);
    }

    public function detail(int $id): JsonResponse
    {
        $station = WeatherStation::find($id);
        if (! $station) {
            return response()->json(['error' => 'Station not found'], 404);
        }

        $startOfDay = Carbon::now()->startOfDay();
        $observations = WeatherStationObservation::where('weather_station_id', $id)
            ->where('observed_at', '>=', $startOfDay)
            ->orderBy('observed_at')
            ->get();

        $fallback = false;
        if ($observations->isEmpty()) {
            $observations = WeatherStationObservation::where('weather_station_id', $id)
                ->where('observed_at', '>=', Carbon::now()->subHours(24))
                ->orderBy('observed_at')
                ->get();
            $fallback = true;
        }

        $latest = $observations->last();

        $readings = $observations->map(function (WeatherStationObservation $obs) use ($startOfDay) {
            $obsTime = $obs->observed_at;
            $minOfDay = $obsTime->hour * 60 + $obsTime->minute;

            return [
                'observed_at'         => $obsTime->toIso8601String(),
                'time'                => $obsTime->format('H:i'),
                'min_of_day'          => $minOfDay,
                'wind_direction'      => $obs->wind_direction,
                'wind_speed_avg'      => $obs->wind_speed_avg,
                'wind_speed_max'      => $obs->wind_speed_max,
                'wind_speed_max_10m'  => $obs->wind_speed_max_10m,
                'wind_direction_max'  => $obs->wind_direction_max,
                'wind_direction_gust' => $obs->wind_direction_gust,
                'temperature'         => $obs->temperature,
                'temperature_min'     => $obs->temperature_min,
                'temperature_max'     => $obs->temperature_max,
                'humidity'            => $obs->humidity,
                'humidity_min'        => $obs->humidity_min,
                'humidity_max'        => $obs->humidity_max,
                'pressure_hpa'        => $obs->pressure_hpa,
                'precipitation_mm'    => $obs->precipitation_mm,
                'cloud_cover_pct'     => $obs->cloud_cover_pct,
                'visibility_m'        => $obs->visibility_m,
                'dew_point'           => $obs->dew_point,
            ];
        })->values()->all();

        return response()->json([
            'station' => [
                'id'         => $station->id,
                'network'    => $station->network,
                'name'       => $station->name,
                'latitude'   => (float) $station->latitude,
                'longitude'  => (float) $station->longitude,
                'altitude_m' => $station->altitude_m,
            ],
            'latest'   => $latest ? [
                'observed_at'         => $latest->observed_at->toIso8601String(),
                'wind_direction'      => $latest->wind_direction,
                'wind_speed_avg'      => $latest->wind_speed_avg,
                'wind_speed_max'      => $latest->wind_speed_max,
                'temperature'         => $latest->temperature,
                'humidity'            => $latest->humidity,
                'pressure_hpa'        => $latest->pressure_hpa,
                'precipitation_mm'    => $latest->precipitation_mm,
                'cloud_cover_pct'     => $latest->cloud_cover_pct,
                'visibility_m'        => $latest->visibility_m,
                'dew_point'           => $latest->dew_point,
            ] : null,
            'readings' => $readings,
            'fallback' => $fallback,
        ]);
    }

    /**
     * @return array{float,float,float,float}|null [latMin, latMax, lngMin, lngMax]
     */
    private function parseBbox(Request $request): ?array
    {
        $raw = $request->query('bbox');
        if (! is_string($raw)) {
            return null;
        }
        $parts = explode(',', $raw);
        if (count($parts) !== 4) {
            return null;
        }
        $nums = array_map('floatval', $parts);
        if ($nums[0] >= $nums[1] || $nums[2] >= $nums[3]) {
            return null;
        }
        return $nums;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildForBbox(float $latMin, float $latMax, float $lngMin, float $lngMax): array
    {
        $stations = WeatherStation::active()
            ->withWindSensor()
            ->whereBetween('latitude', [$latMin, $latMax])
            ->whereBetween('longitude', [$lngMin, $lngMax])
            ->with(['latestObservation'])
            ->get();

        return $this->serialize($stations);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildBundle(): array
    {
        $stations = WeatherStation::active()
            ->withWindSensor()
            ->with(['latestObservation'])
            ->get();

        return $this->serialize($stations);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function serialize($stations): array
    {
        return $stations->map(function (WeatherStation $s) {
            $obs = $s->latestObservation;
            $reading = null;

            if ($obs) {
                $reading = [
                    'observed_at'    => $obs->observed_at->toIso8601String(),
                    'wind_direction' => $obs->wind_direction,
                    'wind_speed_avg' => $obs->wind_speed_avg,
                    'wind_speed_max' => $obs->wind_speed_max,
                    'temperature'    => $obs->temperature,
                    'humidity'       => $obs->humidity,
                ];
            }

            return [
                'id'         => $s->id,
                'network'    => $s->network,
                'name'       => $s->name,
                'lat'        => (float) $s->latitude,
                'lng'        => (float) $s->longitude,
                'altitude_m' => $s->altitude_m,
                'reading'    => $reading,
            ];
        })->values()->all();
    }
}
