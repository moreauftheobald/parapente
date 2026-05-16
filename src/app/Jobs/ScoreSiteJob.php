<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Map\SiteDetailCache;
use App\Services\Weather\ScoringService;
use App\Services\Weather\UserScoringService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recalcule et persiste les scores d'un site.
 *
 * Dispatché en fin de chaîne par FetchForecastsJob, une fois tous les
 * FetchSiteModelJob du cycle exécutés — ça évite les N re-scorings
 * redondants (un après chaque modèle).
 *
 * À la fin, on invalide les caches détail du site (scores/chart/multimodel)
 * et les caches user-scoring du site — leurs valeurs sont désormais
 * obsolètes. Cf. FF_map_bundle_cache.md (phase 2).
 */
class ScoreSiteJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 120;
    public int $tries   = 1;

    public function __construct(
        private readonly int $siteId
    ) {}

    public function handle(
        ScoringService $scoring,
        SiteDetailCache $detailCache,
        UserScoringService $userScoring,
    ): void {
        $site = Site::with('conditions')->find($this->siteId);
        if (! $site) {
            Log::error("ScoreSiteJob: site {$this->siteId} introuvable.");
            return;
        }

        $scoring->computeScoresForSite($site);

        // Les consensus du site ont changé : on purge les caches qui en
        // dépendent (détail volet droit + user-scoring perso). Au
        // prochain accès, ils seront reconstruits avec les nouveaux scores.
        $detailCache->forgetSite($site->id);
        $userScoring->invalidateSite($site->id);

        Log::info("ScoreSiteJob: scores recalculés [{$site->slug}].");
    }
}
