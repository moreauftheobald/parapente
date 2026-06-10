<?php

declare(strict_types=1);

namespace App\Services\Map;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class WeatherStationsBundleCache
{
    public const CACHE_VERSION = 2;

    public const BUNDLE_TTL_SECONDS = 300;

    /** Comparaison mesures vs consensus (onglet Évolution) : 10 min. */
    public const COMPARISON_TTL_SECONDS = 600;

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

    public static function comparisonKey(int $stationId): string
    {
        return "map.weather_stations.comparison.{$stationId}.v" . self::CACHE_VERSION;
    }

    /**
     * @param callable():array $builder
     * @return array<string,mixed>
     */
    public function rememberComparison(int $stationId, callable $builder): array
    {
        return $this->cache->remember(self::comparisonKey($stationId), self::COMPARISON_TTL_SECONDS, $builder);
    }
}
