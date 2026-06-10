<?php

declare(strict_types=1);

namespace App\Services\Map;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache transparent des endpoints balises (/api/balises +
 * /api/balises/{id}/history).
 *
 * Particularités vs SiteDetailCache :
 *  - **TTL court** : 5 min pour le bundle global (les readings bougent
 *    en temps quasi-réel, +/- 5 min selon les jobs PiouPiou/METAR/Windy)
 *    et 2 min pour l'historique (graphes du volet droit).
 *  - **Invalidation push** par chaque job FetchXxxReadingsJob à la fin
 *    de son cycle d'ingestion : forget le bundle global. Les détails
 *    par balise sont laissés au TTL (granularité fine pas nécessaire).
 *  - **Pas de logique user-spec** : balises = données publiques, cache
 *    servi à tout le monde tel quel.
 *
 * Cf. FF_map_bundle_cache.md (phase 3).
 *
 * IMPORTANT : on ne stocke que des primitives/arrays dans le cache —
 * jamais d'objets Eloquent (cf. point 11 CLAUDE.md). Toute évolution
 * de la structure DOIT s'accompagner d'un bump de CACHE_VERSION.
 */
final class BalisesBundleCache
{
    public const CACHE_VERSION = 1;

    /**
     * Bundle global : 5 min. Les jobs PiouPiou tournent souvent (toutes
     * les ~5-10 min selon la cadence). Le TTL est un fallback ; en régime
     * nominal le cache est purgé immédiatement à la fin de chaque job.
     */
    public const BUNDLE_TTL_SECONDS = 300;

    /**
     * Historique d'une balise : 2 min. Moins critique (consulté
     * uniquement sur clic), mais on garde court car les graphes doivent
     * refléter rapidement les nouveaux relevés.
     */
    public const HISTORY_TTL_SECONDS = 120;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public static function bundleKey(): string
    {
        return 'map.balises.bundle.v' . self::CACHE_VERSION;
    }

    public static function historyKey(int $baliseId, ?int $windowHours = null): string
    {
        $suffix = $windowHours !== null ? ".w{$windowHours}" : '';
        return "map.balises.history.{$baliseId}{$suffix}.v" . self::CACHE_VERSION;
    }

    /**
     * @param callable():array $builder
     * @return array<int,array<string,mixed>>
     */
    public function rememberBundle(callable $builder): array
    {
        return $this->cache->remember(self::bundleKey(), self::BUNDLE_TTL_SECONDS, $builder);
    }

    /**
     * @param callable():array $builder
     * @return array<string,mixed>
     */
    public function rememberHistory(int $baliseId, callable $builder, ?int $windowHours = null): array
    {
        return $this->cache->remember(self::historyKey($baliseId, $windowHours), self::HISTORY_TTL_SECONDS, $builder);
    }

    /**
     * Purge le bundle global. Appelé à la fin de chaque job
     * FetchXxxReadingsJob (PiouPiou, METAR, Windy) — les readings ont
     * changé pour au moins une balise, donc le payload du bundle est
     * obsolète.
     *
     * Ne purge PAS les historiques par balise : ils sont consultés à
     * la demande uniquement, le TTL court (2 min) suffit. Éviter le
     * scan de toutes les clés Redis pour rester O(1).
     */
    public function forgetBundle(): void
    {
        $this->cache->forget(self::bundleKey());
    }

    /**
     * Purge l'historique d'une balise spécifique. Appelé seulement si
     * on veut forcer une mise à jour immédiate (debug / admin).
     */
    public function forgetHistory(int $baliseId): void
    {
        $this->cache->forget(self::historyKey($baliseId));
    }
}
