<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Stations\MfStationProvider;
use App\Services\Stations\StationProviderInterface;

/**
 * Polling infrahoraire (toutes les 12 min) des observations Météo-France
 * via /paquet/stations/infrahoraire-6m.
 *
 * 2 appels HTTP par run (2 slots de 6 min) → toutes les stations MF.
 * Cron toutes les 12 min à +9 min du slot pair (ex: xx:09 → xx:00 + xx:06).
 * Coût : 10 appels/heure (quota MF = 100 req/min).
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
