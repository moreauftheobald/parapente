<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\Site;
use App\Models\SiteScore;
use App\Models\User;
use App\Models\UserSiteCondition;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;

/**
 * Calcul + cache du scoring personnalisé d'un utilisateur sur un site.
 *
 * On ne persiste **pas** les scores par user-site (cf.
 * FF_personnal_scoring.md, comparatif persistant vs cache) : ils sont
 * calculés à la volée à partir des consensus déjà stockés en
 * `site_scores`, puis cachés en Redis ~1 h.
 *
 * Invalidations :
 *  - fin de FetchSiteForecastsJob pour le site → `invalidateSite()`
 *  - activation / désactivation / édition d'un scoring → `invalidate()`
 *  - admin change un seuil global de scoring → `invalidateAll()`
 */
class UserScoringService
{
    public const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private ScoringService $scoring,
        private CacheRepository $cache,
    ) {
    }

    /**
     * Rejoue le scoring sur tous les `SiteScore` d'un site avec les
     * conditions personnelles d'un utilisateur. Renvoie null si l'user
     * n'a pas de scoring actif sur ce site.
     *
     * Structure renvoyée : map `forecast_at (Y-m-d H:i:s) =>
     * ['status' => string, 'colors' => array<string,string>]`.
     *
     * @return array<string, array{status:string, colors:array<string,string>}>|null
     */
    public function rescoreForUserSite(int $userId, int $siteId): ?array
    {
        $usc = UserSiteCondition::forUser($userId)
            ->where('site_id', $siteId)
            ->active()
            ->first();

        if ($usc === null) {
            return null;
        }

        return $this->cache->remember(
            $this->cacheKey($userId, $siteId),
            self::CACHE_TTL_SECONDS,
            function () use ($usc, $siteId): array {
                $scores = SiteScore::onActiveBuffer()->where('site_id', $siteId)->get();
                $out    = [];

                foreach ($scores as $score) {
                    $out[$score->forecast_at->format('Y-m-d H:i:s')] =
                        $this->scoring->rescore($usc, $score);
                }

                return $out;
            }
        );
    }

    /**
     * Active un scoring perso en appliquant la rotation LRU :
     * si l'utilisateur atteint le plafond `UserSiteCondition::MAX_ACTIVE`,
     * désactive le plus ancien (`MIN(activated_at)`).
     *
     * Exécuté dans une transaction avec verrou de lecture pour absorber
     * les double-clics. Retourne le scoring activé et celui éventuellement
     * désactivé.
     *
     * @return array{activated: UserSiteCondition, demoted: ?UserSiteCondition}
     */
    public function activate(UserSiteCondition $usc): array
    {
        return DB::transaction(function () use ($usc): array {
            // Si déjà actif, on rafraîchit juste activated_at (LRU touch).
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
                $this->invalidate($demoted->user_id, $demoted->site_id);
            }

            $usc->update([
                'is_active'    => true,
                'activated_at' => now(),
            ]);
            $this->invalidate($usc->user_id, $usc->site_id);

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
        $this->invalidate($usc->user_id, $usc->site_id);
    }

    /**
     * Purge une clé de cache (user × site).
     */
    public function invalidate(int $userId, int $siteId): void
    {
        $this->cache->forget($this->cacheKey($userId, $siteId));
    }

    /**
     * Purge tous les caches `user × {site}` à brancher après chaque
     * FetchSiteForecastsJob (les consensus du site ont changé).
     * S'appuie sur l'index `usc_user_active_lru_idx`.
     */
    public function invalidateSite(int $siteId): void
    {
        UserSiteCondition::query()
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->pluck('user_id')
            ->each(fn (int $userId) => $this->invalidate($userId, $siteId));
    }

    /**
     * Purge tous les caches user-scoring (admin a changé un seuil global).
     */
    public function invalidateAll(): void
    {
        UserSiteCondition::query()
            ->where('is_active', true)
            ->get(['user_id', 'site_id'])
            ->each(fn ($r) => $this->invalidate((int) $r->user_id, (int) $r->site_id));
    }

    public function cacheKey(int $userId, int $siteId): string
    {
        return "user_scoring:{$userId}:site:{$siteId}";
    }
}
