<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\WeatherModel;
use App\Services\Map\SiteDetailCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Compat / opérations manuelles : fetch tous les modèles actifs pour un
 * site en bypassant la cadence (utile via tinker, ou à l'activation d'un
 * site pour alimenter immédiatement le panel multimodèles).
 *
 * Ne calcule AUCUN score : le scoring est déporté au sidecar
 * `consensus-grid-v2` (tables `site_scores_{1,2}`). Un site nouvellement
 * activé n'aura donc ses statuts qu'au prochain run du sidecar ; seules
 * les prévisions par modèle (`forecasts`, pour la comparaison) sont
 * rafraîchies ici.
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

    public function handle(SiteDetailCache $detailCache): void
    {
        $site = Site::with('conditions')->find($this->siteId);
        if (! $site) {
            Log::error("FetchSiteForecastsJob: site {$this->siteId} introuvable.");
            return;
        }

        $models = WeatherModel::with('api')
            ->where('active', true)
            ->where('code', '!=', WeatherModel::CONSENSUS_CODE)
            ->get();

        Log::info("FetchSiteForecastsJob: début [{$site->slug}] — {$models->count()} modèles.");

        foreach ($models as $model) {
            FetchSiteModelJob::dispatchSync($site->id, $model->id);
        }

        // Les prévisions par modèle du site ont changé : on purge les
        // caches détail qui en dépendent (chart / multimodel). Les scores
        // (volet « scores ») viennent du sidecar et ne bougent pas ici.
        $detailCache->forgetSite($site->id);

        Log::info("FetchSiteForecastsJob: terminé [{$site->slug}].");
    }
}
