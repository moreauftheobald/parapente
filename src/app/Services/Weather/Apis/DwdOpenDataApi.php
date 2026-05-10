<?php

declare(strict_types=1);

namespace App\Services\Weather\Apis;

use App\Models\Site;
use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Support\Facades\Log;

/**
 * DWD OpenData — ICON-D2, ICON-EU, ICON.
 *
 * Implémentation prévue en PR3 :
 * - Téléchargement GRIB2 par variable depuis opendata.dwd.de
 * - Extraction point via GribExtractor (eccodes CLI)
 *
 * Stub pour l'instant.
 */
class DwdOpenDataApi implements WeatherApiInterface
{
    private ?WeatherApi $config = null;

    public function code(): string
    {
        return 'dwd';
    }

    public function setConfig(WeatherApi $config): void
    {
        $this->config = $config;
    }

    public function supportedModelCodes(): array
    {
        return [
            'icon_d2',
            'icon_eu',
            'icon_seamless',
        ];
    }

    public function fetchForSiteAndModel(Site $site, WeatherModel $model): array
    {
        Log::warning('DwdOpenDataApi: not yet implemented (PR3)', [
            'site'  => $site->slug,
            'model' => $model->code,
        ]);
        $this->config?->recordError('DwdOpenDataApi non implémenté (PR3)');
        return [];
    }
}
