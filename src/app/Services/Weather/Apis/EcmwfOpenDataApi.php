<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Log;

/**
 * ECMWF Open Data — IFS-HRES 0.25°, AIFS Single/Ensemble v2.
 *
 * Implémentation prévue en PR3 :
 * - Téléchargement GRIB2 depuis data.ecmwf.int (S3 AWS)
 * - Extraction point via GribExtractor
 *
 * Stub pour l'instant.
 */
class EcmwfOpenDataApi implements WeatherApiInterface
{
    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'ecmwf';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config = $config;
    }

    public function supportedModelCodes(): array
    {
        return [
            'ecmwf_ifs025',
            'ecmwf_aifs025',
        ];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        Log::warning('EcmwfOpenDataApi: not yet implemented (PR3)', [
            'site'  => $site->slug,
            'model' => $model->code,
        ]);
        $this->config?->recordError('EcmwfOpenDataApi non implémenté (PR3)');
        return [];
    }
}
