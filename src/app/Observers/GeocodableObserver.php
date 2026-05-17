<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\GeocodeLocationJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Observer mutualisé pour les modèles géocodables (Site, Balise).
 *
 * - Création : déclenche un géocodage async si pas encore géocodé.
 * - Modification des coordonnées : invalide `geocoded_at` et re-géocode.
 *
 * Enregistré dans `AppServiceProvider::boot()` pour chaque modèle
 * concerné.
 */
class GeocodableObserver
{
    public function created(Model $model): void
    {
        if ($model->getAttribute('geocoded_at') !== null) {
            return;
        }
        if ($model->getAttribute('latitude') === null || $model->getAttribute('longitude') === null) {
            return;
        }
        GeocodeLocationJob::dispatch($model::class, (int) $model->getKey());
    }

    public function updated(Model $model): void
    {
        if (! $model->wasChanged(['latitude', 'longitude'])) {
            return;
        }
        if ($model->getAttribute('latitude') === null || $model->getAttribute('longitude') === null) {
            return;
        }

        // Reset le marqueur pour que les filtres ne désignent plus la
        // localisation obsolète avant que le job ait tourné.
        $model->forceFill([
            'geocoded_at'       => null,
            'geocoded_provider' => null,
        ])->saveQuietly();

        GeocodeLocationJob::dispatch($model::class, (int) $model->getKey());
    }
}
