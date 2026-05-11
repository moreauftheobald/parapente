<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Weather\ScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recalcule et persiste les scores d'un site.
 *
 * Dispatché en fin de chaîne par FetchForecastsJob, une fois tous les
 * FetchSiteModelJob du cycle exécutés — ça évite les N re-scorings
 * redondants (un après chaque modèle).
 */
class ScoreSiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(
        private readonly int $siteId
    ) {}

    public function handle(ScoringService $scoring): void
    {
        $site = Site::with('conditions')->find($this->siteId);
        if (! $site) {
            Log::error("ScoreSiteJob: site {$this->siteId} introuvable.");
            return;
        }

        $scoring->computeScoresForSite($site);
        Log::info("ScoreSiteJob: scores recalculés [{$site->slug}].");
    }
}
