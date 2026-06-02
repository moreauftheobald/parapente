<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WeatherStation;
use App\Services\Map\WeatherStationsBundleCache;
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
