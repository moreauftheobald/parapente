<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Log;

/**
 * Météo-France API — AROME 1.3km/2.5km, ARPEGE 0.1°/0.25°.
 *
 * Implémentation prévue en PR2 :
 * - OAuth2 client_credentials → token (stocké dans weather_apis)
 * - Endpoint WCS GetCoverage (GRIB2)
 * - Extraction point via GribExtractor (eccodes CLI)
 *
 * Stub pour l'instant : retourne [] et log un avertissement.
 */
class MeteoFranceApi implements WeatherApiInterface
{
    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'meteofrance';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config = $config;
    }

    public function supportedModelCodes(): array
    {
        return [
            'meteofrance_arome_france',
            'meteofrance_arpege_europe',
        ];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        Log::warning('MeteoFranceApi: not yet implemented (PR2)', [
            'site'  => $site->slug,
            'model' => $model->code,
        ]);
        $this->config?->recordError('MeteoFranceApi non implémenté (PR2)');
        return [];
    }
}
