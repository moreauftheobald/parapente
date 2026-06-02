<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Stations\MetarStationProvider;
use App\Services\Stations\StationProviderInterface;

/**
 * Polling périodique des METAR via le système stations météo.
 *
 * Remplace FetchMetarReadingsJob (balises) — même fréquence (30 min),
 * mais cible weather_stations / weather_station_observations avec des
 * observations plus riches (pression, visibilité, couverture nuageuse,
 * point de rosée, données brutes).
 */
class FetchMetarStationReadingsJob extends FetchWeatherStationReadingsJob
{
    public int $timeout = 90;

    protected function network(): string
    {
        return 'metar';
    }

    protected function provider(): StationProviderInterface
    {
        return app(MetarStationProvider::class);
    }
}
