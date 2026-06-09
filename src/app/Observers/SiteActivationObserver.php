<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\FetchSiteForecastsJob;
use App\Models\Site;

/**
 * Déclenche un fetch des prévisions PAR MODÈLE quand un site devient
 * actif (création active OU passage inactif → actif), pour alimenter
 * immédiatement le panel multimodèles sans attendre le cron horaire.
 *
 * ⚠️ Le SCORING (statut vert/orange/rouge) est déporté au sidecar
 * `consensus-grid-v2` : un site nouvellement activé apparaît sur la carte
 * (le marqueur est ajouté par `MapBundleInvalidationObserver`) mais reste
 * en statut « inconnu » jusqu'au prochain run du sidecar, qui peuplera
 * `site_scores_{1,2}` pour lui. `FetchSiteForecastsJob` ne rafraîchit donc
 * ici que les `forecasts` (comparaison multimodèles), pas les scores.
 */
class SiteActivationObserver
{
    public function created(Site $site): void
    {
        if ($site->active) {
            FetchSiteForecastsJob::dispatch($site->id);
        }
    }

    public function updated(Site $site): void
    {
        // Fetch uniquement sur la transition inactif → actif.
        // Modif de coords ou de conditions sur un site déjà actif :
        // le prochain cycle horaire couvrira (ou l'admin peut lancer
        // FetchSiteForecastsJob::dispatchSync(id) à la main).
        if ($site->wasChanged('active') && $site->active) {
            FetchSiteForecastsJob::dispatch($site->id);
        }
    }
}
