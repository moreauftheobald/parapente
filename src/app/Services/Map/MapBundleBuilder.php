<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\Site;
use App\Models\SiteScore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Construit le « map bundle » : JSON consolidé contenant TOUTES les
 * données d'affichage des marqueurs de la carte, partagé entre tous
 * les utilisateurs (le scoring perso vit ailleurs, cf.
 * MeScoringController::overrides).
 *
 * - 1 seul fetch HTTP côté client au boot de /carte (vs N+1 avant)
 * - 1 seul read Redis côté serveur en régime nominal
 * - Régénération à la fin de chaque cycle de scoring (≈ 1×/h) via
 *   RebuildMapBundleJob ; fallback lazy si la clé Redis est absente
 *
 * Cf. FF_map_bundle_cache.md pour le cadrage complet.
 *
 * IMPORTANT : on ne stocke que des primitives/arrays dans le cache —
 * jamais d'objets Eloquent (cf. point 11 CLAUDE.md). Toute évolution
 * du schéma du bundle DOIT s'accompagner d'un bump de CACHE_VERSION.
 */
final class MapBundleBuilder
{
    /**
     * Bump à chaque changement de structure du bundle. Les anciennes
     * entrées Redis deviennent orphelines (clé différente) plutôt que
     * de risquer un crash de désérialisation.
     *
     * v2 : ajout de `green_hours_set` par site (day => [heures green])
     *      dans le bundle caché — sert au recalcul de l'agrégat
     *      journalier excluant les sites masqués par un utilisateur
     *      (MeHiddenSitesController). Cette clé est retirée du payload
     *      envoyé au client par MapBundleController::show (pas de bloat
     *      côté front). Cf. FF_site_blacklist.md.
     * v3 : les scores proviennent désormais du double-buffer
     *      `site_scores_{1,2}` écrit par le sidecar consensus-grid-v2
     *      (scoring déporté). Bump pour orpheliner les entrées issues de
     *      l'ancien path de scoring PHP.
     */
    public const CACHE_VERSION = 3;

    /**
     * TTL backup au cas où l'invalidation push (FetchForecastsJob)
     * échoue ou n'est pas branchée. Légèrement plus long que
     * l'intervalle de scoring horaire pour ne pas expirer pile entre
     * deux régénérations.
     */
    public const CACHE_TTL_SECONDS = 5400; // 90 min

    public function __construct(
        private readonly DayQualityCalculator $dayQuality,
        private readonly CacheRepository $cache,
    ) {}

    public static function cacheKey(): string
    {
        return 'map.bundle.v' . self::CACHE_VERSION;
    }

    /**
     * Lit le bundle depuis le cache. Si absent (1er accès après expiry
     * ou après vidage manuel), le construit à la volée et le stocke.
     *
     * @return array<string,mixed>
     */
    public function getOrBuild(): array
    {
        $cached = $this->cache->get(self::cacheKey());
        if (is_array($cached)) {
            return $cached;
        }

        // Fallback lazy : utilise un lock Redis pour éviter le thundering
        // herd (100 users qui rechargent en même temps → 100 régénérations).
        // Le 1er obtient le lock et calcule ; les autres attendent puis lisent
        // la valeur fraîchement écrite. TTL du lock 120 s pour absorber les
        // builds lourds (3000+ sites) ; block 30 s max avant abandon.
        $lock = $this->cache->lock(self::cacheKey() . '.lock', 120);

        try {
            return $lock->block(30, function () {
                $cached = $this->cache->get(self::cacheKey());
                if (is_array($cached)) {
                    return $cached;
                }

                $bundle = $this->build();
                $this->cache->put(self::cacheKey(), $bundle, self::CACHE_TTL_SECONDS);
                return $bundle;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::warning('MapBundleBuilder: lock timeout, renvoi d\'un bundle vide temporaire.');
            return ['sites' => [], 'days_summary' => [], '_stale' => true];
        }
    }

    /**
     * Construit le bundle depuis la DB. Appelé par RebuildMapBundleJob
     * (push) et getOrBuild (fallback lazy).
     *
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $startedAt = microtime(true);

        $sitesPayload = [];

        Site::active()
            ->with('conditions')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$sitesPayload) {
                $siteIds = $chunk->pluck('id');

                $scoresBySite = SiteScore::onActiveBuffer()
                    ->whereIn('site_id', $siteIds)
                    ->upcoming()
                    ->orderBy('forecast_at')
                    ->get()
                    ->groupBy('site_id');

                foreach ($chunk as $site) {
                    $sitesPayload[] = $this->buildSitePayload(
                        $site,
                        $scoresBySite->get($site->id, collect()),
                    );
                }
            });

        $daysSummary = self::summarizeDays($sitesPayload);

        $generatedAt = CarbonImmutable::now();
        $payload = [
            'version'         => self::CACHE_VERSION,
            'generated_at'    => $generatedAt->toIso8601String(),
            'next_refresh_at' => $generatedAt->addHour()->toIso8601String(),
            'sites'           => $sitesPayload,
            'days_summary'    => $daysSummary,
        ];

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        Log::info("MapBundleBuilder::build: {$elapsedMs}ms, " . count($sitesPayload) . ' sites, ' . count($daysSummary) . ' jours.');

        return $payload;
    }

    /**
     * Construit la portion d'un site dans le bundle.
     *
     * - `days` : dict day => {status, viability, green_hours}
     *           ⇒ remplace directement `dayQuality[id]` côté client.
     * - `sun_windows` : dict day => fenêtre solaire pour ce site
     * - `green_hours_set` : ensemble des heures green pour ce site/jour,
     *           utilisé par summarizeDays pour l'agrégat « X h de vol
     *           possible quelque part » et son recalcul par utilisateur
     *           (exclusion des sites masqués). Présent dans le bundle
     *           caché mais retiré du payload envoyé au client par
     *           MapBundleController::show.
     *
     * @return array<string,mixed>
     */
    private function buildSitePayload(Site $site, \Illuminate\Support\Collection $siteScores): array
    {
        $lat = (float) $site->latitude;
        $lng = (float) $site->longitude;

        $grouped    = $siteScores->groupBy(fn (SiteScore $s) => $s->forecast_at->format('d/m'));
        $sunWindows = [];
        $days       = [];
        $greenHoursPerDay = []; // day => [hour, hour, ...] pour agrégat global

        foreach ($grouped as $day => $dayScores) {
            $window           = SunWindowCalculator::compute($lat, $lng, $day);
            $sunWindows[$day] = $window;

            $byHour    = [];
            $greenSet  = [];
            foreach ($dayScores as $score) {
                $h = (int) $score->forecast_at->format('G');
                if ($h < $window['start_hour'] || $h > $window['end_hour']) {
                    continue;
                }
                $status = $score->status ?? 'unknown';
                $byHour[$h] = $status;
                if ($status === 'green') {
                    $greenSet[] = $h;
                }
            }

            $quality = $this->dayQuality->compute($byHour, $window);
            $days[$day] = [
                'status'      => $quality['status'],
                'viability'   => $quality['viability'],
                'green_hours' => $quality['green_hours'],
            ];

            $greenHoursPerDay[$day] = $greenSet;
        }

        return [
            'id'               => $site->id,
            'name'             => $site->name,
            'lat'              => $lat,
            'lng'              => $lng,
            'altitude'         => $site->altitude_m,
            'level'            => $site->level,
            'region'           => $site->region,
            'wind_dir_min'     => $site->conditions?->wind_dir_min,
            'wind_dir_max'     => $site->conditions?->wind_dir_max,
            'days'             => $days,
            'sun_windows'      => $sunWindows,
            'green_hours_set'  => $greenHoursPerDay, // retiré du payload client (cf. MapBundleController)
        ];
    }

    /**
     * Construit l'agrégat global par jour (5 premiers jours rencontrés) :
     *  - `best_status` : meilleur statut observé parmi les sites retenus
     *    pour ce jour (green > orange > red > unknown)
     *  - `green_slots` : nombre d'heures où AU MOINS UN site retenu est
     *    green sur ce jour (union des heures green dédupliquée)
     *
     * Méthode statique pure (pas d'état, pas de DB) : sert au build du
     * bundle (tous les sites) ET au recalcul par utilisateur excluant
     * ses sites masqués (cf. MeHiddenSitesController). Lit les clés
     * `days` et `green_hours_set` de chaque payload site.
     *
     * @param  array<int,array<string,mixed>> $sitesPayload
     * @param  array<int,int>                 $excludedSiteIds  id de sites à ignorer
     * @return array<int,array{raw:string,best_status:string,green_slots:int}>
     */
    public static function summarizeDays(array $sitesPayload, array $excludedSiteIds = []): array
    {
        $statusRank = ['green' => 3, 'orange' => 2, 'red' => 1, 'unknown' => 0];
        $excluded   = array_flip($excludedSiteIds);

        // Collecte des jours connus + agrégat green/best.
        $allDays = [];
        foreach ($sitesPayload as $site) {
            if (isset($excluded[$site['id'] ?? null])) {
                continue;
            }
            foreach ($site['days'] ?? [] as $day => $info) {
                $allDays[$day] ??= ['best' => 'unknown', 'green_hours' => []];
                if (($statusRank[$info['status']] ?? 0) > ($statusRank[$allDays[$day]['best']] ?? 0)) {
                    $allDays[$day]['best'] = $info['status'];
                }
            }
            foreach ($site['green_hours_set'] ?? [] as $day => $hours) {
                $allDays[$day] ??= ['best' => 'unknown', 'green_hours' => []];
                $allDays[$day]['green_hours'] = array_unique(array_merge($allDays[$day]['green_hours'] ?? [], $hours));
            }
        }

        // Tri chronologique (les jours sont en `d/m` ; on reconstitue
        // l'année courante puis l'année suivante si on bascule).
        $year = (int) date('Y');
        $sortable = [];
        foreach (array_keys($allDays) as $day) {
            [$d, $m] = explode('/', $day);
            $sortable[$day] = sprintf('%04d-%02d-%02d', $year, (int) $m, (int) $d);
        }
        // Pas de gestion bascule année — on est sur un horizon 5 jours,
        // les seuls jours en cache sont aujourd'hui + 4 jours futurs ;
        // dans la pire fenêtre fin décembre on aura tous les jours en
        // décembre OU tous en janvier. Si bascule, le tri reste correct
        // car SiteScore::upcoming() filtre déjà sur forecast_at >= now().
        asort($sortable);

        $out = [];
        foreach (array_keys($sortable) as $day) {
            $out[] = [
                'raw'         => $day,
                'best_status' => $allDays[$day]['best'],
                'green_slots' => count($allDays[$day]['green_hours']),
            ];
            if (count($out) >= 5) break; // horizon 5 jours
        }

        return $out;
    }

    /**
     * Invalide le cache du bundle. À appeler après une opération qui
     * change globalement les données (admin modifie un seuil, etc.).
     * Le cas normal (fin du scoring horaire) passe par
     * RebuildMapBundleJob qui régénère directement.
     */
    public function invalidate(): void
    {
        $this->cache->forget(self::cacheKey());
    }
}
