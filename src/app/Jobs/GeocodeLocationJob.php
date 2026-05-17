<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Geocoding\ReverseGeocoderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Géocode un Site ou une Balise à la création (observer) ou à la
 * mise à jour des coordonnées.
 *
 * On passe le couple (classe, id) plutôt qu'une instance Eloquent pour
 * éviter les soucis de sérialisation et garantir qu'on travaille sur
 * l'état le plus à jour au moment du run.
 */
class GeocodeLocationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries   = 3;

    /** @return array<int> backoff exponentiel en secondes */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(
        public readonly string $modelClass,
        public readonly int $id,
    ) {
    }

    public function handle(ReverseGeocoderInterface $geocoder): void
    {
        if (! class_exists($this->modelClass) || ! is_subclass_of($this->modelClass, Model::class)) {
            Log::warning('GeocodeLocationJob: classe invalide', ['class' => $this->modelClass]);
            return;
        }

        /** @var Model|null $model */
        $model = ($this->modelClass)::find($this->id);
        if ($model === null) {
            return; // supprimé entre temps
        }

        $lat = $model->getAttribute('latitude');
        $lng = $model->getAttribute('longitude');
        if ($lat === null || $lng === null) {
            return;
        }

        $result = $geocoder->reverse((float) $lat, (float) $lng);
        if ($result === null) {
            Log::info('GeocodeLocationJob: aucun match', [
                'class' => $this->modelClass, 'id' => $this->id,
                'lat' => $lat, 'lng' => $lng,
            ]);
            return;
        }

        $model->forceFill([
            'country_code'      => $result->countryCode,
            'country'           => $result->country,
            'admin_region'      => $result->adminRegion,
            'department'        => $result->department,
            'geocoded_provider' => $result->provider,
            'geocoded_at'       => now(),
        ])->saveQuietly(); // saveQuietly → pas de boucle observer (geocoding + map bundle)
    }
}
