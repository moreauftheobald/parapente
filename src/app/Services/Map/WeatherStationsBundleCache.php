<?php

declare(strict_types=1);

namespace App\Services\Map;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class WeatherStationsBundleCache
{
    public const CACHE_VERSION = 1;

    public const BUNDLE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public static function bundleKey(): string
    {
        return 'map.weather_stations.bundle.v' . self::CACHE_VERSION;
    }

    /**
     * @param callable():array $builder
     * @return array<int,array<string,mixed>>
     */
    public function rememberBundle(callable $builder): array
    {
        return $this->cache->remember(self::bundleKey(), self::BUNDLE_TTL_SECONDS, $builder);
    }

    public function forgetBundle(): void
    {
        $this->cache->forget(self::bundleKey());
    }
}
