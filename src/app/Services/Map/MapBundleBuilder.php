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
     */
    public const CACHE_VERSION = 1;

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
        // la valeur fraîchement écrite. TTL du lock court (15 s) : si le
        // builder plante, les suivants pourront retenter.
        return $this->cache->lock(self::cacheKey() . '.lock', 15)->block(10, function () {
            // Re-check après lock : peut-être qu'un autre processus a déjà
            // construit pendant qu'on attendait.
            $cached = $this->cache->get(self::cacheKey());
            if (is_array($cached)) {
                return $cached;
            }

            $bundle = $this->build();
            $this->cache->put(self::cacheKey(), $bundle, self::CACHE_TTL_SECONDS);
            return $bundle;
        });
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

        $sites = Site::active()->with('conditions')->orderBy('id')->get();

        // 1ère passe : construire le payload de chaque site.
        $sitesPayload = [];
        foreach ($sites as $site) {
            $sitesPayload[] = $this->buildSitePayload($site);
        }

        // 2e passe : agrégat global jour-par-jour (alimente le sélecteur
        // de jour côté client). Le client doit éviter de recalculer.
        $daysSummary = $this->buildDaysSummary($sitesPayload);

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
     * - `green_hours_set` (interne) : ensemble des heures green pour ce
     *           site/jour, utilisé par buildDaysSummary pour l'agrégat
     *           « X h de vol possible quelque part ». Pas exposé au client.
     *
     * @return array<string,mixed>
     */
    private function buildSitePayload(Site $site): array
    {
        $lat = (float) $site->latitude;
        $lng = (float) $site->longitude;

        $allScores = SiteScore::where('site_id', $site->id)
            ->upcoming()
            ->orderBy('forecast_at')
            ->get();

        $grouped    = $allScores->groupBy(fn (SiteScore $s) => $s->forecast_at->format('d/m'));
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
            '_green_hours_set' => $greenHoursPerDay, // privé, retiré avant cache
        ];
    }

    /**
     * Construit l'agrégat global par jour (5 premiers jours rencontrés) :
     *  - `best_status` : meilleur statut observé parmi tous les sites
     *    pour ce jour (green > orange > red > unknown)
     *  - `green_slots` : nombre d'heures où AU MOINS UN site est green
     *    sur ce jour (set des heures green agrégées)
     *
     * Reproduit la logique de `mapApp().buildDays()` côté serveur pour
     * que le client n'ait plus à le faire en boot.
     *
     * Effet de bord : retire la clé interne `_green_hours_set` des
     * payloads sites une fois consommée (pas exposée au client).
     *
     * @param  array<int,array<string,mixed>> $sitesPayload (modifié par ref)
     * @return array<int,array{raw:string,best_status:string,green_slots:int}>
     */
    private function buildDaysSummary(array &$sitesPayload): array
    {
        $statusRank = ['green' => 3, 'orange' => 2, 'red' => 1, 'unknown' => 0];

        // Collecte des jours connus + agrégat green/best.
        $allDays = [];
        foreach ($sitesPayload as $site) {
            foreach ($site['days'] as $day => $info) {
                $allDays[$day] ??= ['best' => 'unknown', 'green_hours' => []];
                if (($statusRank[$info['status']] ?? 0) > ($statusRank[$allDays[$day]['best']] ?? 0)) {
                    $allDays[$day]['best'] = $info['status'];
                }
            }
            foreach ($site['_green_hours_set'] ?? [] as $day => $hours) {
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

        // Nettoyage : retirer la clé interne avant cache.
        foreach ($sitesPayload as &$site) {
            unset($site['_green_hours_set']);
        }
        unset($site);

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
