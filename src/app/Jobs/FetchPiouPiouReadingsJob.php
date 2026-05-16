<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Balises\BaliseProviderInterface;
use App\Services\Balises\PiouPiouProvider;

/**
 * Polling périodique des lectures PiouPiou (schedulé toutes les 10 min).
 *
 * Cf. FetchBaliseReadingsJob pour la logique mutualisée.
 */
class FetchPiouPiouReadingsJob extends FetchBaliseReadingsJob
{
    public int $timeout = 60;

    protected function source(): string
    {
        return 'pioupiou';
    }

    protected function provider(): BaliseProviderInterface
    {
        return app(PiouPiouProvider::class);
    }
}
