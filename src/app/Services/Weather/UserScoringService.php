<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\UserSiteCondition;
use App\Services\Settings;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scoring personnalisé d'un utilisateur, **déporté au sidecar**.
 *
 * Le calcul (règles éliminatoires + couleurs) n'est plus fait en PHP : on
 * envoie en un seul appel batch les sites perso ACTIFS de l'utilisateur
 * (coords + conditions custom) + les seuils globaux au sidecar
 * `consensus-grid-v2` (`POST /v1/scoring/custom`), qui lit son propre
 * forecast et score avec la MÊME logique que le scoring prod. Le résultat
 * est caché en Redis (1 entrée par user). Cf. FF_personnal_scoring_sidecar.md.
 *
 * Trois déclencheurs d'invalidation :
 *  - flip du buffer de scoring (nouveau run sidecar) → `invalidateAll()`
 *    (WatchScoringTableJob)
 *  - l'utilisateur édite / active / désactive un scoring → `invalidateUser()`
 *  - l'admin change un seuil global → `invalidateAll()` (Settings::flush)
 *
 * Fallback : si le sidecar est injoignable, on ne cache rien et on renvoie
 * un overlay vide → l'appelant sert le scoring global.
 */
class UserScoringService
{
    public const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private CustomScoringClient $client,
        private Settings $settings,
        private CacheRepository $cache,
    ) {
    }

    /**
     * Scoring perso de TOUS les sites actifs de l'utilisateur (1 appel
     * sidecar, caché par user).
     *
     * @return array<int, array<string, array{status:string, colors:array<string,?string>}>>
     *         map site_id => (map forecast_at 'Y-m-d H:i:s' => {status, colors})
     */
    public function rescoreForUser(int $userId): array
    {
        $cached = $this->cache->get($this->cacheKey($userId));
        if (is_array($cached)) {
            return $cached;
        }

        $computed = $this->computeForUser($userId);
        if ($computed === null) {
            // Sidecar injoignable : pas d'overlay, et surtout on NE CACHE PAS
            // (sinon l'utilisateur resterait sans perso 1 h après le retour
            // du sidecar). Retry au prochain accès.
            return [];
        }

        $this->cache->put($this->cacheKey($userId), $computed, self::CACHE_TTL_SECONDS);

        return $computed;
    }

    /**
     * Scoring perso d'un site donné. Renvoie null si l'utilisateur n'a pas
     * de scoring actif sur ce site OU si le sidecar est injoignable (dans
     * les deux cas l'appelant retombe sur le scoring global).
     *
     * @return array<string, array{status:string, colors:array<string,?string>}>|null
     */
    public function rescoreForUserSite(int $userId, int $siteId): ?array
    {
        return $this->rescoreForUser($userId)[$siteId] ?? null;
    }

    /**
     * Construit le payload, appelle le sidecar et parse la réponse.
     * Renvoie [] si l'utilisateur n'a aucun scoring actif (résultat
     * cacheable), null si le sidecar échoue (non cacheable).
     *
     * @return array<int, array<string, array{status:string, colors:array<string,?string>}>>|null
     */
    private function computeForUser(int $userId): ?array
    {
        $conditions = UserSiteCondition::forUser($userId)
            ->active()
            ->with('site')
            ->get()
            ->filter(fn (UserSiteCondition $usc) => $usc->site && $usc->site->active)
            ->values();

        if ($conditions->isEmpty()) {
            return [];
        }

        $response = $this->client->score($this->buildPayload($conditions));
        if ($response === null) {
            return null;
        }

        return $this->parseResponse($response);
    }

    /**
     * @param Collection<int,UserSiteCondition> $conditions
     * @return array<string,mixed>
     */
    private function buildPayload(Collection $conditions): array
    {
        return [
            'thresholds' => [
                'precip_orange_mmh' => (float) $this->settings->get('scoring.precip_orange_mmh'),
                'precip_red_mmh'    => (float) $this->settings->get('scoring.precip_red_mmh'),
                'gust_orange_kmh'   => (float) $this->settings->get('scoring.gust_orange_kmh'),
                'gust_red_kmh'      => (float) $this->settings->get('scoring.gust_red_kmh'),
            ],
            'sites' => $conditions->map(fn (UserSiteCondition $usc) => [
                'ref'        => (string) $usc->site_id,
                'latitude'   => (float) $usc->site->latitude,
                'longitude'  => (float) $usc->site->longitude,
                'altitude_m' => $usc->site->altitude_m !== null ? (int) $usc->site->altitude_m : null,
                'conditions' => [
                    'wind_dir_min'         => (int) $usc->wind_dir_min,
                    'wind_dir_max'         => (int) $usc->wind_dir_max,
                    'wind_speed_min'       => (int) $usc->wind_speed_min,
                    'wind_speed_max'       => (int) $usc->wind_speed_max,
                    'wind_speed_ideal'     => $usc->wind_speed_ideal !== null ? (int) $usc->wind_speed_ideal : null,
                    'wind_gust_orange_kmh' => $usc->wind_gust_orange_kmh !== null ? (float) $usc->wind_gust_orange_kmh : null,
                    'wind_gust_red_kmh'    => $usc->wind_gust_red_kmh !== null ? (float) $usc->wind_gust_red_kmh : null,
                    'cloud_base_min_m'     => $usc->cloud_base_min_m !== null ? (int) $usc->cloud_base_min_m : null,
                    'cloud_cover_low_max'  => $usc->cloud_cover_low_max !== null ? (int) $usc->cloud_cover_low_max : null,
                ],
            ])->all(),
        ];
    }

    /**
     * Transforme la réponse sidecar en map `site_id => (forecast_at => {status, colors})`.
     *
     * - `ref` (= site_id en string) → clé int.
     * - `forecast_at` (ISO UTC) → clé 'Y-m-d H:i:s' (aligne sur le format de
     *   `site_scores.forecast_at` consommé par les builders/overlay).
     * - `colors.<param>.{consensus,color}` → `colors.<param>` = string color
     *   (les builders ne consomment que la couleur).
     *
     * @param array<string,mixed> $response
     * @return array<int, array<string, array{status:string, colors:array<string,?string>}>>
     */
    private function parseResponse(array $response): array
    {
        $out = [];

        foreach (($response['sites'] ?? []) as $site) {
            $siteId = (int) ($site['ref'] ?? 0);
            if ($siteId === 0) {
                continue;
            }

            $slots = [];
            foreach (($site['slots'] ?? []) as $slot) {
                if (! isset($slot['forecast_at'], $slot['status'])) {
                    continue;
                }

                $key = Carbon::parse($slot['forecast_at'])->format('Y-m-d H:i:s');

                $colors = [];
                foreach (($slot['colors'] ?? []) as $param => $info) {
                    $colors[$param] = is_array($info) ? ($info['color'] ?? null) : $info;
                }

                $slots[$key] = [
                    'status' => (string) $slot['status'],
                    'colors' => $colors,
                ];
            }

            $out[$siteId] = $slots;
        }

        return $out;
    }

    /**
     * Active un scoring perso en appliquant la rotation LRU :
     * si l'utilisateur atteint le plafond `UserSiteCondition::MAX_ACTIVE`,
     * désactive le plus ancien (`MIN(activated_at)`).
     *
     * @return array{activated: UserSiteCondition, demoted: ?UserSiteCondition}
     */
    public function activate(UserSiteCondition $usc): array
    {
        return DB::transaction(function () use ($usc): array {
            $alreadyActive = (bool) $usc->is_active;

            $otherActives = UserSiteCondition::forUser($usc->user_id)
                ->active()
                ->where('id', '!=', $usc->id)
                ->lockForUpdate()
                ->orderBy('activated_at')
                ->get();

            $demoted = null;
            if (! $alreadyActive && $otherActives->count() >= UserSiteCondition::MAX_ACTIVE) {
                $demoted = $otherActives->first();
                $demoted->update(['is_active' => false]);
            }

            $usc->update([
                'is_active'    => true,
                'activated_at' => now(),
            ]);

            // Le jeu de sites actifs du user a changé → invalide son entrée.
            $this->invalidateUser($usc->user_id);

            return [
                'activated' => $usc->refresh(),
                'demoted'   => $demoted?->refresh(),
            ];
        });
    }

    public function deactivate(UserSiteCondition $usc): void
    {
        if (! $usc->is_active) {
            return;
        }

        $usc->update(['is_active' => false]);
        $this->invalidateUser($usc->user_id);
    }

    /**
     * Purge l'entrée de cache d'un utilisateur (édition / activation /
     * désactivation d'un de ses scorings).
     */
    public function invalidateUser(int $userId): void
    {
        $this->cache->forget($this->cacheKey($userId));
    }

    /**
     * Purge le cache de tous les utilisateurs ayant un scoring actif
     * (flip du buffer de scoring, ou changement d'un seuil global).
     */
    public function invalidateAll(): void
    {
        UserSiteCondition::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('user_id')
            ->each(fn ($userId) => $this->invalidateUser((int) $userId));
    }

    public function cacheKey(int $userId): string
    {
        return "scoring_custom:user:{$userId}";
    }
}
