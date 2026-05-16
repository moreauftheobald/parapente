<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Map\MapBundleBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Régénère manuellement le map bundle et l'écrit en cache.
 *
 * En production, le bundle est régénéré automatiquement à la fin de
 * chaque cycle de scoring (FetchForecastsJob via Bus::batch finally).
 * Cette commande est utile :
 *  - en dev pour tester sans attendre le cron
 *  - en prod pour forcer une régénération après un changement manuel
 *    (admin, seed, etc.)
 *
 * Cf. FF_map_bundle_cache.md.
 */
class MapBundleRebuild extends Command
{
    protected $signature = 'map:rebuild-bundle {--clear : juste vider le cache, sans regénérer}';
    protected $description = 'Régénère le map bundle et écrit en cache Redis.';

    public function handle(MapBundleBuilder $builder, CacheRepository $cache): int
    {
        if ($this->option('clear')) {
            $builder->invalidate();
            $this->info('Cache map bundle vidé (clé : ' . MapBundleBuilder::cacheKey() . ').');
            return self::SUCCESS;
        }

        $this->info('Construction du map bundle...');
        $start  = microtime(true);
        $bundle = $builder->build();
        $cache->put(MapBundleBuilder::cacheKey(), $bundle, MapBundleBuilder::CACHE_TTL_SECONDS);
        $ms     = (int) round((microtime(true) - $start) * 1000);

        $this->info("Bundle écrit en cache en {$ms}ms.");
        $this->line(' - Sites    : ' . count($bundle['sites'] ?? []));
        $this->line(' - Jours    : ' . count($bundle['days_summary'] ?? []));
        $this->line(' - Clé      : ' . MapBundleBuilder::cacheKey());
        $this->line(' - TTL      : ' . MapBundleBuilder::CACHE_TTL_SECONDS . ' s');
        $this->line(' - Taille   : ' . number_format(strlen(json_encode($bundle)) / 1024, 1) . ' KB');

        return self::SUCCESS;
    }
}
