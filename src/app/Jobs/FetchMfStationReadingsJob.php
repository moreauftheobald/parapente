<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Stations\MfStationProvider;
use App\Services\Stations\StationProviderInterface;

/**
 * Polling horaire des observations Météo-France via /paquet/stations/horaire.
 *
 * 1 seul appel HTTP → toutes les stations MF de France.
 * Observations enrichies (vent, température, pression, humidité,
 * précipitations, couverture nuageuse, visibilité, point de rosée).
 */
class FetchMfStationReadingsJob extends FetchWeatherStationReadingsJob
{
    public int $timeout = 120;

    protected function network(): string
    {
        return 'mf';
    }

    protected function provider(): StationProviderInterface
    {
        return app(MfStationProvider::class);
    }
}
