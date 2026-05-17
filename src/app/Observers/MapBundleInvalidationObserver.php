<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RebuildMapBundleJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer qui invalide le map bundle (cache Redis `/api/map-bundle`)
 * lors d'un changement de Site ou de Balise.
 *
 * - `created()`  : nouveau site/balise → réapparaît sur la carte
 * - `updated()`  : changement de `active`, coords, nom, niveau,
 *                  altitude ou atterrissage → bundle obsolète
 * - `deleted()`  : disparait de la carte
 *
 * Le job dispatché est `ShouldBeUnique` (lock 30s) + dispatched avec
 * un délai de 5s. Effet : 100 toggles rapides → 1 seul rebuild après
 * un court délai (debounce naturel).
 *
 * ⚠ Comme tout observer Eloquent, ne se déclenche PAS sur les
 * `Builder::update()` en masse (qui bypass les events). Pour ce cas,
 * lancer manuellement `php artisan map:rebuild-bundle`.
 */
class MapBundleInvalidationObserver
{
    /** Champs qui, s'ils changent, justifient un rebuild du bundle. */
    private const RELEVANT_FIELDS = [
        'active', 'latitude', 'longitude',
        'name', 'altitude_m', 'level',
        'landing_lat', 'landing_lng',
    ];

    public function created(Model $model): void
    {
        $this->scheduleRebuild();
    }

    public function updated(Model $model): void
    {
        if (! $model->wasChanged(self::RELEVANT_FIELDS)) {
            return;
        }
        $this->scheduleRebuild();
    }

    public function deleted(Model $model): void
    {
        $this->scheduleRebuild();
    }

    private function scheduleRebuild(): void
    {
        // Délai 5s + lock unique 30s = au plus 1 rebuild toutes les
        // ~5-30 secondes, peu importe la rafale d'événements.
        RebuildMapBundleJob::dispatch()->delay(now()->addSeconds(5));
    }
}
