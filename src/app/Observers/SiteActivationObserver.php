<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\FetchSiteForecastsJob;
use App\Models\Site;

/**
 * Déclenche un fetch météo + scoring immédiat quand un site devient
 * actif (création active OU passage inactif → actif).
 *
 * Sans ça, l'activation d'un site n'a aucun effet visible avant le
 * prochain cycle cron horaire — et il peut s'écouler jusqu'à 12h
 * pour qu'un site nouvellement activé voie ses ~13 modèles météo
 * tous récupérés.
 *
 * Le job `FetchSiteForecastsJob` :
 *  - fetch les 13 modèles du site en synchrone (~30-60s)
 *  - lance le scoring
 *  - invalide les caches du site
 *  - dispatch `RebuildMapBundleJob`
 *
 * Donc une seule chose à dispatcher pour rattraper toute la chaîne.
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
