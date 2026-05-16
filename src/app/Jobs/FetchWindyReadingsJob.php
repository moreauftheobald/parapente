<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Balises\BaliseProviderInterface;
use App\Services\Balises\WindyOpenDataProvider;

/**
 * Polling périodique des lectures Windy.com (Open Data, schedulé toutes
 * les 30 min — plus conservateur que PiouPiou car le provider fait N
 * appels HTTP, un par balise).
 *
 * Cf. FetchBaliseReadingsJob pour la logique mutualisée. Un batch vide
 * est silencieux (cas légitime : pas de clé API configurée).
 */
class FetchWindyReadingsJob extends FetchBaliseReadingsJob
{
    public int $timeout = 300;

    protected function source(): string
    {
        return 'windy';
    }

    protected function provider(): BaliseProviderInterface
    {
        return app(WindyOpenDataProvider::class);
    }

    protected function warnOnEmptyBatch(): bool
    {
        return false;
    }
}
