<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteScore;
use App\Services\Map\MapBundleBuilder;
use App\Services\Map\ScoringFreshness;
use App\Services\Map\SiteDetailCache;
use App\Services\Weather\UserScoringService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Surveille le pointeur `scoring_table`, flippé par le sidecar
 * `consensus-grid-v2` après chaque run consensus (écriture du buffer
 * inactif puis bascule atomique du setting). Au changement de buffer
 * actif, les statuts/consensus de TOUS les sites ont été remplacés : on
 * invalide les caches qui en dépendent et on régénère le map bundle.
 *
 * Planifié toutes les minutes (`routes/console.php`). Idempotent : ne fait
 * rien tant que le buffer n'a pas changé (compare la table active à la
 * dernière vue, mémorisée en cache).
 *
 * Remplace l'invalidation « push » de l'ancien ScoreSiteJob (scoring
 * déporté). Cf. la bascule scoring V2.
 */
class WatchScoringTableJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries   = 1;

    /** Dernier buffer actif observé (mémorisé en cache, sans TTL). */
    public const SEEN_KEY = 'map.scoring_table.seen';

    public function handle(
        SiteDetailCache $detailCache,
        UserScoringService $userScoring,
        ScoringFreshness $freshness,
        CacheRepository $cache,
    ): void {
        $current = SiteScore::activeTableName();
        $seen    = $cache->get(self::SEEN_KEY);

        if ($seen !== $current) {
            // Flip détecté (ou tout premier passage après déploiement) :
            // les scores servis depuis le cache sont périmés.
            $cache->forget(MapBundleBuilder::cacheKey());

            // Les 3 caches détail par site (scores/chart/multimodel) lisent
            // tous des colonnes de `site_scores` → invalidation site par site.
            Site::query()->select('id')->chunkById(500, function ($sites) use ($detailCache) {
                foreach ($sites as $site) {
                    $detailCache->forgetSite($site->id);
                }
            });

            $userScoring->invalidateAll();
            RebuildMapBundleJob::dispatch();

            $cache->forever(self::SEEN_KEY, $current);

            // Un flip = un nouveau run sidecar → recale le watchdog de fraîcheur.
            $freshness->recordRun();

            Log::info("WatchScoringTableJob: flip du buffer scoring → {$current} (précédent: " . ($seen ?? '∅') . '), caches invalidés + rebuild bundle dispatché.');
        }

        // Watchdog de fraîcheur — évalué CHAQUE minute (surtout en l'absence
        // de flip : c'est justement le signal de péremption). Alerte au
        // passage frais → périmé + met à jour le flag exposé au front.
        $freshness->evaluate();
    }
}
