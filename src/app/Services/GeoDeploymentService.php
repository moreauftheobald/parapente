<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Balise;
use App\Models\Site;
use App\Services\Balises\BaliseProviderInterface;
use App\Services\Balises\MetarProvider;
use App\Services\Balises\PiouPiouProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Déploiement géographique : à partir d'une ville et d'un rayon en km,
 * active tous les sites de la base situés dans la zone, puis découvre
 * (via les providers) et active toutes les balises de chaque réseau
 * (pioupiou, metar) dans la même zone.
 *
 * Règles :
 *  - Les sites/balises hors zone ne sont jamais désactivés (cumulatif).
 *  - Une balise déjà désactivée manuellement reste désactivée
 *    (cohérent avec BaliseController : la désactivation manuelle
 *    bloque la réactivation auto).
 *  - Géocodage : API Open-Meteo (gratuit, sans clé).
 */
class GeoDeploymentService
{
    private const GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
    private const HTTP_TIMEOUT_S = 15;

    /** Rayon terrestre moyen en km (Haversine) */
    private const EARTH_RADIUS_KM = 6371.0;

    public function __construct(
        private readonly PiouPiouProvider $piouPiou,
        private readonly MetarProvider $metar,
    ) {}

    /**
     * Géocode une ville via Open-Meteo. Retourne null si introuvable.
     *
     * @return array{name:string,country:string,admin1:?string,latitude:float,longitude:float}|null
     */
    public function geocode(string $city): ?array
    {
        $resp = Http::timeout(self::HTTP_TIMEOUT_S)
            ->get(self::GEOCODING_URL, [
                'name'     => $city,
                'count'    => 1,
                'language' => 'fr',
                'format'   => 'json',
            ]);

        if (! $resp->ok()) {
            Log::warning('Geocoding HTTP error', ['status' => $resp->status(), 'city' => $city]);
            return null;
        }

        $hit = $resp->json('results.0');
        if (! $hit || ! isset($hit['latitude'], $hit['longitude'])) {
            return null;
        }

        return [
            'name'      => (string) ($hit['name'] ?? $city),
            'country'   => (string) ($hit['country'] ?? ''),
            'admin1'    => isset($hit['admin1']) ? (string) $hit['admin1'] : null,
            'latitude'  => (float) $hit['latitude'],
            'longitude' => (float) $hit['longitude'],
        ];
    }

    /**
     * Lance le déploiement complet et retourne la synthèse.
     *
     * @return array{
     *   location: array{name:string,country:string,admin1:?string,latitude:float,longitude:float},
     *   radius_km: float,
     *   bbox: array{lat_min:float,lat_max:float,lng_min:float,lng_max:float},
     *   sites: array{in_zone:int,newly_activated:int,already_active:int},
     *   balises: array<string, array{discovered:int,in_zone:int,created:int,newly_activated:int,already_active:int,kept_off:int}>
     * }
     */
    public function deploy(string $city, float $radiusKm): array
    {
        $loc = $this->geocode($city);
        if (! $loc) {
            throw new \RuntimeException("Ville introuvable : {$city}");
        }

        $bbox = $this->bbox($loc['latitude'], $loc['longitude'], $radiusKm);

        $sites = $this->activateSitesInRadius($loc['latitude'], $loc['longitude'], $radiusKm, $bbox);

        $balises = [];
        foreach ($this->providers() as $key => $provider) {
            $balises[$key] = $this->discoverAndActivateBalises(
                $provider,
                $loc['latitude'],
                $loc['longitude'],
                $radiusKm,
                $bbox,
            );
        }

        return [
            'location'  => $loc,
            'radius_km' => $radiusKm,
            'bbox'      => $bbox,
            'sites'     => $sites,
            'balises'   => $balises,
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * Calcule une bounding box autour d'un point pour un rayon en km.
     * Approximation : 1° lat ≈ 111 km ; 1° lng ≈ 111 × cos(lat) km.
     * On élargit légèrement (×1.05) pour absorber l'arrondi avant filtre haversine final.
     *
     * @return array{lat_min:float,lat_max:float,lng_min:float,lng_max:float}
     */
    private function bbox(float $lat, float $lng, float $radiusKm): array
    {
        $deltaLat = ($radiusKm * 1.05) / 111.0;
        $cos = max(0.01, cos(deg2rad($lat))); // évite la division par 0 aux pôles
        $deltaLng = ($radiusKm * 1.05) / (111.0 * $cos);

        return [
            'lat_min' => round($lat - $deltaLat, 4),
            'lat_max' => round($lat + $deltaLat, 4),
            'lng_min' => round($lng - $deltaLng, 4),
            'lng_max' => round($lng + $deltaLng, 4),
        ];
    }

    /**
     * Distance Haversine en km entre deux points.
     */
    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * @return array{in_zone:int,newly_activated:int,already_active:int}
     */
    private function activateSitesInRadius(float $lat, float $lng, float $radiusKm, array $bbox): array
    {
        $candidates = Site::query()
            ->whereBetween('latitude',  [$bbox['lat_min'], $bbox['lat_max']])
            ->whereBetween('longitude', [$bbox['lng_min'], $bbox['lng_max']])
            ->get(['id', 'latitude', 'longitude', 'active']);

        $inZone = 0;
        $newlyActivated = 0;
        $alreadyActive = 0;

        foreach ($candidates as $site) {
            $d = $this->distanceKm($lat, $lng, (float) $site->latitude, (float) $site->longitude);
            if ($d > $radiusKm) {
                continue;
            }
            $inZone++;

            if ($site->active) {
                $alreadyActive++;
                continue;
            }
            $site->active = true;
            $site->save();
            $newlyActivated++;
        }

        return [
            'in_zone'         => $inZone,
            'newly_activated' => $newlyActivated,
            'already_active'  => $alreadyActive,
        ];
    }

    /**
     * @return array{discovered:int,in_zone:int,created:int,newly_activated:int,already_active:int,kept_off:int}
     */
    private function discoverAndActivateBalises(
        BaliseProviderInterface $provider,
        float $lat,
        float $lng,
        float $radiusKm,
        array $bbox,
    ): array {
        $stations = $provider->discoverStations(
            $bbox['lat_min'],
            $bbox['lat_max'],
            $bbox['lng_min'],
            $bbox['lng_max'],
        );
        $discovered = count($stations);

        $inZone = 0;
        $created = 0;
        $newlyActivated = 0;
        $alreadyActive = 0;
        $keptOff = 0;

        foreach ($stations as $s) {
            $d = $this->distanceKm($lat, $lng, (float) $s['latitude'], (float) $s['longitude']);
            if ($d > $radiusKm) {
                continue;
            }
            $inZone++;

            $balise = Balise::firstOrNew([
                'source'      => $provider->source(),
                'external_id' => $s['external_id'],
            ]);
            $isNew = ! $balise->exists;

            $balise->fill([
                'name'       => $s['name'],
                'latitude'   => $s['latitude'],
                'longitude'  => $s['longitude'],
                'altitude_m' => $s['altitude_m'],
            ]);

            if ($isNew) {
                $balise->active = true;
                $balise->save();
                $created++;
                $newlyActivated++;
                continue;
            }

            // Existante : on respecte la désactivation manuelle.
            if ($balise->active) {
                $alreadyActive++;
            } else {
                $keptOff++;
            }
            $balise->save();
        }

        return [
            'discovered'      => $discovered,
            'in_zone'         => $inZone,
            'created'         => $created,
            'newly_activated' => $newlyActivated,
            'already_active'  => $alreadyActive,
            'kept_off'        => $keptOff,
        ];
    }

    /**
     * @return array<string, BaliseProviderInterface>
     */
    private function providers(): array
    {
        return [
            $this->piouPiou->source() => $this->piouPiou,
            $this->metar->source()    => $this->metar,
        ];
    }
}
