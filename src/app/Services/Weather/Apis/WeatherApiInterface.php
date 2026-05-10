<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;

/**
 * Contrat commun pour toutes les sources API de prévisions météo
 * (Open-Meteo, MET Norway, Météo-France, DWD OpenData, ECMWF Open Data…).
 *
 * Convention de retour pour fetchForSiteAndModel() : tableau associatif
 * indexé par datetime au format 'Y-m-d H:i:s' (timezone Europe/Paris) :
 *
 *   '2026-05-08 14:00:00' => [
 *       'wind_direction'   => int (°, FROM, 0=N)
 *       'wind_speed_avg'   => float (km/h)
 *       'wind_speed_min'   => float (km/h)
 *       'wind_speed_max'   => float (km/h, rafale si dispo)
 *       'precipitation'    => float (mm)
 *       'cloud_cover_low'  => int (%)
 *       'cloud_cover_mid'  => int (%)
 *       'cloud_cover_high' => int (%)
 *       'cloud_base_m'     => ?int (m)
 *       'temperature'      => float (°C)
 *       'humidity'         => int (%)
 *   ]
 */
interface WeatherApiInterface
{
    /**
     * Slug technique unique (correspond à weather_apis.code).
     */
    public function code(): string;

    /**
     * Liste des codes de modèles que cette API sait servir.
     * Sert au filtrage dans l'admin.
     *
     * @return array<int, string>
     */
    public function supportedModelCodes(): array;

    /**
     * Récupère les prévisions d'un modèle pour un site.
     * Retourne un tableau vide en cas d'échec (logger l'erreur en interne).
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array;

    /**
     * Injecte la configuration runtime (api_key, user_agent, base_url…).
     * Appelée par WeatherApiRegistry après instanciation.
     */
    public function setConfig(WeatherApi $config): void;
}
