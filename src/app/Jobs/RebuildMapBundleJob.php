<?php

declare(strict_types=1);

namespace App\Jobs;

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
 *  - à la fin du cycle horaire de scoring (FetchForecastsJob,
 *    ScoreSiteJob) → bundle reflète les derniers scores
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

    public int $timeout = 60;
    public int $tries   = 2;

    /** Lock TTL pour le ShouldBeUnique (secondes). */
    public int $uniqueFor = 30;

    public function uniqueId(): string
    {
        return 'rebuild-map-bundle';
    }

    public function handle(MapBundleBuilder $builder, CacheRepository $cache): void
    {
        $bundle = $builder->build();
        $cache->put(MapBundleBuilder::cacheKey(), $bundle, MapBundleBuilder::CACHE_TTL_SECONDS);

        Log::info(
            'RebuildMapBundleJob: cache écrit, '
            . count($bundle['sites'] ?? []) . ' sites, '
            . count($bundle['days_summary'] ?? []) . ' jours.'
        );
    }
}
