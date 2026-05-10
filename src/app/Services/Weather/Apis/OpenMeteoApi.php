<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenMeteoApi implements WeatherApiInterface
{
    private const DAYS            = 5;
    private const WIND_UNIT       = 'kmh';
    private const TIMEZONE        = 'Europe/Paris';
    private const BATCH_TIMEOUT_S = 60;
    private const BATCH_CHUNK     = 40;

    private const HOURLY_VARS = [
        'wind_speed_10m',
        'wind_gusts_10m',
        'wind_direction_10m',
        'precipitation',
        'cloud_cover',
        'cloud_cover_low',
        'cloud_cover_mid',
        'cloud_cover_high',
        'temperature_2m',
        'relative_humidity_2m',
    ];

    private string $baseUrl = 'https://api.open-meteo.com/v1';
    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'openmeteo';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config  = $config;
        $this->baseUrl = rtrim($config->base_url ?: $this->baseUrl, '/');
    }

    public function supportedModelCodes(): array
    {
        // 13 modèles servis par le serveur Open-Meteo self-hosted dédié
        // au projet (cf. OPEN_METEO_MODELS dans la config docker).
        return [
            'meteofrance_arome_france_hd',
            'meteofrance_arome_france_hd_15m',
            'meteofrance_arpege_europe',
            'dwd_icon_eu',
            'dwd_icon_d2',
            'dwd_icon',
            'ncep_gfs013',
            'ecmwf_ifs025',
            'ecmwf_aifs025_single',
            'ukmo_global_deterministic_10km',
            'bom_access_global',
            'cma_grapes_global',
            'jma_gsm',
        ];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        try {
            $response = Http::timeout(15)
                ->get($this->baseUrl . '/forecast', [
                    'latitude'        => $site->latitude,
                    'longitude'       => $site->longitude,
                    'hourly'          => implode(',', self::HOURLY_VARS),
                    'models'          => $model->code,
                    'forecast_days'   => self::DAYS,
                    'wind_speed_unit' => self::WIND_UNIT,
                    'timezone'        => self::TIMEZONE,
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $msg = "Open-Meteo HTTP {$response->status()} site={$site->slug} model={$model->code}";
                Log::warning($msg);
                $this->config?->recordError($msg);
                return [];
            }

            $this->config?->recordSuccess();
            return $this->parseResponse($response->json());
        } catch (\Exception $e) {
            Log::error('OpenMeteoApi fetch failed', [
                'site'    => $site->slug,
                'model'   => $model->code,
                'message' => $e->getMessage(),
            ]);
            $this->config?->recordError($e->getMessage());
            return [];
        }
    }

    /**
     * Fetch batch multi-coordonnées pour les balises (non-standard,
     * spécifique à Open-Meteo et hors interface).
     *
     * @param  array<int, array{id:int|string, lat:float, lng:float}> $points
     */
    public function fetchBatchForBalises(array $points, WeatherModel $model): array
    {
        if (empty($points)) {
            return [];
        }

        $result = [];
        $chunks = array_chunk(array_values($points), self::BATCH_CHUNK);
        foreach ($chunks as $chunk) {
            $partial = $this->fetchBatchChunk($chunk, $model);
            foreach ($partial as $id => $parsed) {
                $result[$id] = $parsed;
            }
        }
        return $result;
    }

    private function fetchBatchChunk(array $points, WeatherModel $model): array
    {
        $lats = array_map(fn ($p) => (string) $p['lat'], $points);
        $lngs = array_map(fn ($p) => (string) $p['lng'], $points);
        $ids  = array_map(fn ($p) => $p['id'], $points);

        try {
            $response = Http::timeout(self::BATCH_TIMEOUT_S)
                ->get($this->baseUrl . '/forecast', [
                    'latitude'        => implode(',', $lats),
                    'longitude'       => implode(',', $lngs),
                    'hourly'          => 'wind_speed_10m,wind_gusts_10m,wind_direction_10m,temperature_2m',
                    'models'          => $model->code,
                    'forecast_days'   => self::DAYS,
                    'wind_speed_unit' => self::WIND_UNIT,
                    'timezone'        => self::TIMEZONE,
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                Log::warning('OpenMeteo batch error', [
                    'model'       => $model->code,
                    'status'      => $response->status(),
                    'point_count' => count($points),
                ]);
                return [];
            }

            $payload  = $response->json();
            $stations = isset($payload[0]) ? $payload : [$payload];

            $result = [];
            foreach ($stations as $idx => $stationData) {
                $id = $ids[$idx] ?? null;
                if ($id === null) {
                    continue;
                }
                $parsed = $this->parseBaliseResponse($stationData);
                if (! empty($parsed)) {
                    $result[$id] = $parsed;
                }
            }
            return $result;
        } catch (\Exception $e) {
            Log::error('OpenMeteo batch failed', [
                'model'       => $model->code,
                'message'     => $e->getMessage(),
                'point_count' => count($points),
            ]);
            return [];
        }
    }

    private function parseBaliseResponse(array $data): array
    {
        $hourly = $data['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];
        if (empty($times)) {
            return [];
        }

        $parsed = [];
        foreach ($times as $index => $time) {
            $forecastAt = \Carbon\Carbon::parse($time, self::TIMEZONE);
            if ($forecastAt->isPast()) {
                continue;
            }

            $dir   = $this->getValue($hourly, 'wind_direction_10m', $index);
            $speed = $this->getValue($hourly, 'wind_speed_10m', $index);
            if ($dir === null || $speed === null) {
                continue;
            }

            $gust = $this->getValue($hourly, 'wind_gusts_10m', $index);
            $temp = $this->getValue($hourly, 'temperature_2m', $index);

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction' => (int) $dir,
                'wind_speed_avg' => (float) $speed,
                'wind_speed_min' => (float) $speed,
                'wind_speed_max' => (float) ($gust ?? $speed),
                'temperature'    => $temp !== null ? (float) $temp : 0.0,
            ];
        }
        return $parsed;
    }

    private function parseResponse(array $data): array
    {
        $hourly = $data['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];

        if (empty($times)) {
            return [];
        }

        $parsed = [];

        foreach ($times as $index => $time) {
            $forecastAt = \Carbon\Carbon::parse($time, self::TIMEZONE);
            if ($forecastAt->isPast()) {
                continue;
            }

            $windDir   = $this->getValue($hourly, 'wind_direction_10m', $index);
            $windSpeed = $this->getValue($hourly, 'wind_speed_10m', $index);
            $humidity  = $this->getValue($hourly, 'relative_humidity_2m', $index);

            if ($windDir === null || $windSpeed === null || $humidity === null) {
                continue;
            }

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction'   => $this->getValue($hourly, 'wind_direction_10m', $index),
                'wind_speed_avg'   => $this->getValue($hourly, 'wind_speed_10m', $index),
                'wind_speed_min'   => $this->getValue($hourly, 'wind_speed_10m', $index),
                'wind_speed_max'   => $this->getValue($hourly, 'wind_gusts_10m', $index),
                'precipitation'    => $this->getValue($hourly, 'precipitation', $index, 0.0),
                'cloud_cover_low'  => $this->getValue($hourly, 'cloud_cover_low', $index, 0),
                'cloud_cover_mid'  => $this->getValue($hourly, 'cloud_cover_mid', $index, 0),
                'cloud_cover_high' => $this->getValue($hourly, 'cloud_cover_high', $index, 0),
                'cloud_base_m'     => $this->estimateCloudBase(
                    $this->getValue($hourly, 'temperature_2m', $index),
                    $this->getValue($hourly, 'relative_humidity_2m', $index)
                ),
                'temperature'      => $this->getValue($hourly, 'temperature_2m', $index),
                'humidity'         => $this->getValue($hourly, 'relative_humidity_2m', $index),
            ];
        }

        return $parsed;
    }

    private function getValue(array $hourly, string $key, int $index, mixed $default = null): mixed
    {
        return $hourly[$key][$index] ?? $default;
    }

    /**
     * Plafond nuageux estimé via formule de Henning.
     */
    private function estimateCloudBase(?float $temperature, ?int $humidity): ?int
    {
        if ($temperature === null || $humidity === null) {
            return null;
        }

        $dewPoint  = $temperature - ((100 - $humidity) / 5);
        $cloudBase = (int) (($temperature - $dewPoint) / 8 * 1000);

        return max(0, $cloudBase);
    }
}
