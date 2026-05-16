<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Map\MapBundleBuilder;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Régénère le map bundle et le stocke en cache Redis.
 *
 * Dispatché à la fin du cycle horaire de scoring (cf.
 * FetchForecastsJob ou ScoreSiteJob) pour que le bundle servi à
 * `/api/map-bundle` reflète toujours les derniers scores.
 *
 * Idempotent : si plusieurs jobs sont dispatchés (compteur Redis qui
 * atteint 0 deux fois pour une raison X), le 2e écrase juste le
 * cache avec le même contenu.
 *
 * Cf. FF_map_bundle_cache.md.
 */
class RebuildMapBundleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries   = 2;

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
