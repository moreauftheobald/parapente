<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\Site;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenMeteoService
{
    private const BASE_URL   = 'https://api.open-meteo.com/v1/forecast';
    private const DAYS       = 5;
    private const WIND_UNIT  = 'kmh';
    private const TIMEZONE   = 'Europe/Paris';

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

    /**
     * Récupère les prévisions d'un modèle pour un site.
     * Retourne un tableau de prévisions horaires indexé par datetime.
     */
    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        try {
            $response = Http::timeout(15)
                ->get(self::BASE_URL, [
                    'latitude'        => $site->latitude,
                    'longitude'       => $site->longitude,
                    'hourly'          => implode(',', self::HOURLY_VARS),
                    'models'          => $model->code,
                    'forecast_days'   => self::DAYS,
                    'wind_speed_unit' => self::WIND_UNIT,
                    'timezone'        => self::TIMEZONE,
                ]);

            if (! $response->successful()) {
                Log::warning('OpenMeteo API error', [
                    'site'   => $site->slug,
                    'model'  => $model->code,
                    'status' => $response->status(),
                ]);
                return [];
            }

            return $this->parseResponse($response->json());

        } catch (\Exception $e) {
            Log::error('OpenMeteo fetch failed', [
                'site'    => $site->slug,
                'model'   => $model->code,
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Récupère les prévisions de TOUS les modèles actifs pour un site.
     * Retourne : ['model_code' => [prévisions horaires], ...]
     */
    public function fetchAllModelsForSite(Site $site): array
    {
        $models  = WeatherModel::active()->get();
        $results = [];

        foreach ($models as $model) {
            $horizon = $model->max_horizon_h;

            // On ne fetch que si le modèle couvre au moins quelques heures
            if ($horizon < 1) {
                continue;
            }

            $data = $this->fetchForSiteAndModel($site, $model);

            if (! empty($data)) {
                $results[$model->code] = $data;
            }

            // Petite pause pour ne pas spammer l'API
            usleep(200_000); // 200ms
        }

        return $results;
    }

    /**
     * Parse la réponse JSON Open-Meteo en tableau horaire exploitable.
     *
     * Retourne : [
     *   '2025-06-15T14:00' => [
     *     'wind_direction' => 220,
     *     'wind_speed_avg' => 18.5,
     *     ...
     *   ],
     *   ...
     * ]
     */
    private function parseResponse(array $data): array
    {
        $hourly = $data['hourly'] ?? [];
        $times  = $hourly['time'] ?? [];

        if (empty($times)) {
            return [];
        }

        $parsed = [];

        foreach ($times as $index => $time) {
            // On ne garde que les créneaux dans l'horizon 5 jours
            $forecastAt = \Carbon\Carbon::parse($time, self::TIMEZONE);
            if ($forecastAt->isPast()) {
                continue;
            }

            // Ignorer les créneaux où les données essentielles sont nulles
            $windDir   = $this->getValue($hourly, 'wind_direction_10m', $index);
            $windSpeed = $this->getValue($hourly, 'wind_speed_10m', $index);
            $humidity  = $this->getValue($hourly, 'relative_humidity_2m', $index);

            if ($windDir === null || $windSpeed === null || $humidity === null) {
                continue;
            }

            $parsed[$forecastAt->format('Y-m-d H:i:s')] = [
                'wind_direction'  => $this->getValue($hourly, 'wind_direction_10m', $index),
                'wind_speed_avg'  => $this->getValue($hourly, 'wind_speed_10m', $index),
                'wind_speed_min'  => $this->getValue($hourly, 'wind_speed_10m', $index),    // Open-Meteo ne fournit pas de min horaire
                'wind_speed_max'  => $this->getValue($hourly, 'wind_gusts_10m', $index),    // rafales = max
                'precipitation'   => $this->getValue($hourly, 'precipitation', $index, 0.0),
                'cloud_cover_low' => $this->getValue($hourly, 'cloud_cover_low', $index, 0),
                'cloud_cover_mid' => $this->getValue($hourly, 'cloud_cover_mid', $index, 0),
                'cloud_cover_high'=> $this->getValue($hourly, 'cloud_cover_high', $index, 0),
                'cloud_base_m'    => $this->estimateCloudBase(
                    $this->getValue($hourly, 'temperature_2m', $index),
                    $this->getValue($hourly, 'relative_humidity_2m', $index)
                ),
                'temperature'     => $this->getValue($hourly, 'temperature_2m', $index),
                'humidity'        => $this->getValue($hourly, 'relative_humidity_2m', $index),
            ];
        }

        return $parsed;
    }

    /**
     * Récupère une valeur dans le tableau horaire, avec fallback.
     */
    private function getValue(array $hourly, string $key, int $index, mixed $default = null): mixed
    {
        return $hourly[$key][$index] ?? $default;
    }

    /**
     * Estime le plafond nuageux en mètres via la formule de Henning.
     * (Open-Meteo ne fournit pas directement le plafond en mètres)
     *
     * Formule : altitude (m) ≈ ((T - Td) / 8) * 1000
     * où Td = point de rosée ≈ T - ((100 - HR) / 5)
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
