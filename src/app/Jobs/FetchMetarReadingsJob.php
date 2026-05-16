<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Balises\BaliseProviderInterface;
use App\Services\Balises\MetarProvider;

/**
 * Polling périodique des METAR (NOAA Aviation Weather Center, schedulé
 * toutes les 30 min).
 *
 * Cf. FetchBaliseReadingsJob pour la logique mutualisée.
 */
class FetchMetarReadingsJob extends FetchBaliseReadingsJob
{
    public int $timeout = 90;

    protected function source(): string
    {
        return 'metar';
    }

    protected function provider(): BaliseProviderInterface
    {
        return app(MetarProvider::class);
    }
}
