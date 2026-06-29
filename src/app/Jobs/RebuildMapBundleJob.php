<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Services\Map\MapBundleBuilder;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Régénère le map bundle et le stocke en cache Redis.
 *
 * Dispatché :
 *  - au flip du buffer de scoring écrit par le sidecar
 *    (WatchScoringTableJob) → bundle reflète les derniers scores
 *  - lors d'un changement de Site/Balise (activation, suppression,
 *    déplacement…) via MapBundleInvalidationObserver
 *
 * Idempotent : plusieurs dispatches concurrents → le dernier écrase
 * juste le cache avec le contenu le plus récent.
 *
 * `ShouldBeUnique` avec uniqueFor=30s : si 100 toggles se font en
 * quelques secondes, un seul job est créé (les suivants sont
 * rejetés par le lock unique). Combiné avec un delay() côté
 * observer, ça donne un effet de debounce naturel.
 *
 * Cf. FF_map_bundle_cache.md.
 */
class RebuildMapBundleJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    use TracksExecution;

    // Le build parcourt ~1100 sites × ~120 créneaux horaires : même après
    // l'optimisation DB::table (passage de plusieurs minutes à quelques
    // secondes), on garde une marge confortable pour ne JAMAIS tuer le job
    // avant l'écriture du cache (un timeout < durée de build laissait le
    // cache vide → carte sans marqueurs, cf. régression historique).
    public int $timeout = 300;
    public int $tries   = 2;

    /** Lock TTL pour le ShouldBeUnique (secondes). */
    public int $uniqueFor = 30;

    public function uniqueId(): string
    {
        return 'rebuild-map-bundle';
    }

    protected function monitorGroup(): string
    {
        return 'sites';
    }

    public function handle(MapBundleBuilder $builder, CacheRepository $cache): void
    {
        $this->trackStart();

        $bundle = $builder->build();
        $cache->put(MapBundleBuilder::cacheKey(), $bundle, MapBundleBuilder::CACHE_TTL_SECONDS);

        $this->trackSuccess(count($bundle['sites'] ?? []) . ' sites, ' . count($bundle['days_summary'] ?? []) . ' jours');

        Log::info(
            'RebuildMapBundleJob: cache écrit, '
            . count($bundle['sites'] ?? []) . ' sites, '
            . count($bundle['days_summary'] ?? []) . ' jours.'
        );
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }
}
