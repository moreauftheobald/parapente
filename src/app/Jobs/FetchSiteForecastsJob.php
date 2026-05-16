<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Map\SiteDetailCache;
use App\Services\Weather\ScoringService;
use App\Services\Weather\UserScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Compat / opérations manuelles : fetch tous les modèles actifs pour un
 * site en bypassant la cadence (utile via tinker ou pour réinitialiser
 * un site).
 *
 * En production, l'orchestration normale passe par FetchForecastsJob
 * (cron) qui respecte `refresh_frequency_minutes`.
 */
class FetchSiteForecastsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 1;

    public function __construct(
        private readonly int $siteId
    ) {}

    public function handle(
        ScoringService $scoring,
        UserScoringService $userScoring,
        SiteDetailCache $detailCache,
    ): void {
        $site = Site::with('conditions')->find($this->siteId);
        if (! $site) {
            Log::error("FetchSiteForecastsJob: site {$this->siteId} introuvable.");
            return;
        }

        $models = WeatherModel::with('api')->where('active', true)->get();

        Log::info("FetchSiteForecastsJob: début [{$site->slug}] — {$models->count()} modèles.");

        foreach ($models as $model) {
            FetchSiteModelJob::dispatchSync($site->id, $model->id, false);
        }

        // Scoring unique en fin de salve (évite N rescoring redondants)
        $scoring->computeScoresForSite($site);

        // Les consensus du site ont changé : on purge les caches qui en
        // dépendent — détail volet droit (scores/chart/multimodel) +
        // user-scoring perso. Cf. FF_map_bundle_cache.md (phase 2).
        $detailCache->forgetSite($site->id);
        $userScoring->invalidateSite($site->id);

        // Régénérer le map bundle pour que `/api/map-bundle` reflète les
        // nouveaux scores du site sans attendre le prochain cycle horaire.
        RebuildMapBundleJob::dispatch();

        Log::info("FetchSiteForecastsJob: terminé [{$site->slug}].");
    }
}
