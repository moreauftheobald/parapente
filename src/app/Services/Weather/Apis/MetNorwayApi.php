<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MET Norway — Locationforecast 2.0 (compact).
 *
 * - Pas de clé API. User-Agent obligatoire (sinon 403).
 * - Pas de quota dur, mais 20 req/s max et bannissement si répétitions
 *   sur la même URL > 1/s.
 * - Sortie JSON, post-traitement de MEPS (Nordique 2.5km) + ECMWF/IFS.
 *   Couvre le monde entier mais résolution variable hors zone Nordique.
 *
 * Modèle servi : `metno_seamless` (alias interne).
 */
class MetNorwayApi implements WeatherApiInterface
{
    private const TIMEZONE = 'Europe/Paris';

    private string $baseUrl   = 'https://api.met.no/weatherapi';
    private string $userAgent = 'ParapenteFR/1.0 contact@parapentefr.local';
    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'metno';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config = $config;
        if ($config->base_url) {
            $this->baseUrl = rtrim($config->base_url, '/');
        }
        if ($config->user_agent) {
            $this->userAgent = $config->user_agent;
        }
    }

    public function supportedModelCodes(): array
    {
        return ['metno_seamless'];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => $this->userAgent])
                ->get($this->baseUrl . '/locationforecast/2.0/compact', [
                    'lat' => round((float) $site->latitude, 4),
                    'lon' => round((float) $site->longitude, 4),
                ]);

            $this->config?->incrementRequestsToday();

            if (! $response->successful()) {
                $msg = "MET Norway HTTP {$response->status()} site={$site->slug}";
                Log::warning($msg);
                $this->config?->recordError($msg);
                return [];
            }

            $this->config?->recordSuccess();
            return $this->parseResponse($response->json());
        } catch (\Exception $e) {
            Log::error('MetNorwayApi fetch failed', [
                'site'    => $site->slug,
                'message' => $e->getMessage(),
            ]);
            $this->config?->recordError($e->getMessage());
            return [];
        }
    }

    /**
     * Parse le payload Locationforecast compact.
     *
     * Structure simplifiée :
     *   properties.timeseries[*]
     *     .time = ISO 8601 UTC
     *     .data.instant.details = { air_temperature, wind_speed (m/s),
     *                              wind_from_direction, relative_humidity,
     *                              cloud_area_fraction,
     *                              cloud_area_fraction_low/medium/high }
     *     .data.next_1_hours.details.precipitation_amount (mm)
     */
    private function parseResponse(array $data): array
    {
        $series = $data['properties']['timeseries'] ?? [];
        if (empty($series)) {
            return [];
        }

        $parsed = [];
        foreach ($series as $entry) {
            $time = $entry['time'] ?? null;
            if (! $time) {
                continue;
            }

            $forecastAt = \Carbon\Carbon::parse($time)->setTimezone(self::TIMEZONE);
            if ($forecastAt->isPast()) {
                continue;
            }

            // Horizon utile : 5 jours pour rester aligné avec les autres APIs
            if ($forecastAt->diffInDays(now()) > 5) {
                continue;
            }

            $instant = $entry['data']['instant']['details'] ?? [];
            $next1h  = $entry['data']['next_1_hours']['details'] ?? [];

            $windDir = $instant['wind_from_direction'] ?? null;
            $windMs  = $instant['wind_speed'] ?? null;
            $temp    = $instant['air_temperature'] ?? null;
            $rh      = $instant['relative_humidity'] ?? null;

            if ($windDir === null || $windMs === null || $rh === null) {
                continue;
            }

            $windKmh  = (float) $windMs * 3.6;
            $gustMs   = $instant['wind_speed_of_gust'] ?? null;
            $gustKmh  = $gustMs !== null ? (float) $gustMs * 3.6 : $windKmh;
            $precip   = $next1h['precipitation_amount'] ?? 0.0;

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction'   => (int) round($windDir),
                'wind_speed_avg'   => round($windKmh, 2),
                'wind_speed_min'   => round($windKmh, 2),
                'wind_speed_max'   => round($gustKmh, 2),
                'precipitation'    => (float) $precip,
                'cloud_cover_low'  => (int) round($instant['cloud_area_fraction_low'] ?? 0),
                'cloud_cover_mid'  => (int) round($instant['cloud_area_fraction_medium'] ?? 0),
                'cloud_cover_high' => (int) round($instant['cloud_area_fraction_high'] ?? 0),
                'cloud_base_m'     => $this->estimateCloudBase($temp, $rh),
                'temperature'      => $temp !== null ? (float) $temp : null,
                'humidity'         => (int) round($rh),
            ];
        }

        return $parsed;
    }

    private function estimateCloudBase(?float $temperature, ?float $humidity): ?int
    {
        if ($temperature === null || $humidity === null) {
            return null;
        }
        $dewPoint  = $temperature - ((100 - $humidity) / 5);
        $cloudBase = (int) (($temperature - $dewPoint) / 8 * 1000);
        return max(0, $cloudBase);
    }
}
