<?php

declare(strict_types=1);

namespace App\Services\Map;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Cache transparent des endpoints détaillés d'un site (volet droit de
 * la carte) : scores, chart, multimodel.
 *
 * Stratégie :
 *  - `scores` : cache la version "global" (sans rescore perso) ; le
 *    SiteController applique le rescore par-dessus si l'utilisateur
 *    a un scoring perso ACTIF sur ce site (sinon le cache est servi
 *    tel quel — cas dominant).
 *  - `chart` et `multimodel` : pas de logique user-spécifique, cache
 *    complet et servi à tout le monde.
 *
 * Invalidation : à la fin de chaque ScoreSiteJob (et de
 * FetchSiteForecastsJob pour les opérations manuelles), on appelle
 * `forgetSite($siteId)` qui vide toutes les clés du site.
 *
 * Cf. FF_map_bundle_cache.md (phase 2).
 *
 * IMPORTANT : on ne stocke que des primitives/arrays dans le cache —
 * jamais d'objets Eloquent (cf. point 11 CLAUDE.md). Toute évolution
 * de la structure DOIT s'accompagner d'un bump de CACHE_VERSION.
 */
final class SiteDetailCache
{
    // v2 : bascule du consensus vers le sidecar — la liste `models` du
    // payload multimodel exclut `qui_vole_consensus`, et le `detail` des
    // scores issus du consensus API porte un champ `source`.
    // v3 : scoring déporté — scores lus dans le double-buffer
    // `site_scores_{1,2}` (sidecar consensus-grid-v2). Bump pour
    // orpheliner les entrées issues de l'ancien path de scoring PHP.
    public const CACHE_VERSION = 3;

    /**
     * TTL backup au cas où l'invalidation push (ScoreSiteJob) échoue
     * ou n'est pas branchée. Légèrement plus long que l'intervalle de
     * scoring horaire.
     */
    public const CACHE_TTL_SECONDS = 5400; // 90 min

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public static function scoresKey(int $siteId): string
    {
        return "map.site_scores.{$siteId}.v" . self::CACHE_VERSION;
    }

    public static function chartKey(int $siteId): string
    {
        return "map.site_chart.{$siteId}.v" . self::CACHE_VERSION;
    }

    public static function multimodelKey(int $siteId, string $day, string $period): string
    {
        return "map.site_multimodel.{$siteId}.{$day}.{$period}.v" . self::CACHE_VERSION;
    }

    /**
     * Lit ou construit le cache scores d'un site (version global, sans
     * rescore perso). Le builder fourni est invoqué uniquement en cache
     * miss.
     *
     * @param callable():array $builder
     * @return array<string,mixed>
     */
    public function rememberScores(int $siteId, callable $builder): array
    {
        return $this->cache->remember(self::scoresKey($siteId), self::CACHE_TTL_SECONDS, $builder);
    }

    /**
     * @param callable():array $builder
     * @return array<string,mixed>
     */
    public function rememberChart(int $siteId, callable $builder): array
    {
        return $this->cache->remember(self::chartKey($siteId), self::CACHE_TTL_SECONDS, $builder);
    }

    /**
     * @param callable():array $builder
     * @return array<string,mixed>
     */
    public function rememberMultimodel(int $siteId, string $day, string $period, callable $builder): array
    {
        return $this->cache->remember(self::multimodelKey($siteId, $day, $period), self::CACHE_TTL_SECONDS, $builder);
    }

    /**
     * Purge toutes les clés détail d'un site. Appelé à la fin du scoring
     * (les consensus du site ont changé → caches obsolètes).
     *
     * Pour `multimodel` : on génère les clés possibles (horizon 5 jours
     * × 2 périodes) au lieu d'utiliser un pattern Redis (le contrat
     * d'interface CacheRepository ne propose pas de delete par pattern).
     */
    public function forgetSite(int $siteId): void
    {
        $this->cache->forget(self::scoresKey($siteId));
        $this->cache->forget(self::chartKey($siteId));

        // Multimodel : horizon 5 jours autour d'aujourd'hui (-1 jour de
        // safety pour couvrir le décalage timezone). 2 périodes possibles.
        $tz   = new \DateTimeZone('Europe/Paris');
        $base = new \DateTimeImmutable('today', $tz);
        for ($d = -1; $d <= 6; $d++) {
            $day = $base->modify("{$d} day")->format('Y-m-d');
            foreach (['daylight', '24h'] as $period) {
                $this->cache->forget(self::multimodelKey($siteId, $day, $period));
            }
        }
    }
}
