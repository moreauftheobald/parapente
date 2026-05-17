<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * Orchestrateur reverse geocoding en 2 tiers :
 *   1. geo.api.gouv.fr — point-in-polygon sur les communes françaises
 *                        (métropole + DOM), pas de rate limit ;
 *                        couvre 100% des coords FR
 *   2. Nominatim       — fallback monde entier (BE, LU, DE, CH…),
 *                        rate-limité 1 req/s
 *
 * Les coords FR (~80% du volume) passent en geo.api.gouv : gratuit,
 * rapide, exhaustif. Nominatim ne sert que pour l'étranger, donc le
 * rate limit n'est pas un goulot d'étranglement en pratique.
 */
class HybridReverseGeocoder implements ReverseGeocoderInterface
{
    public function __construct(
        private readonly GeoApiGouvReverseGeocoder $geoApiGouv,
        private readonly NominatimReverseGeocoder $nominatim,
    ) {
    }

    public function reverse(float $lat, float $lng): ?LocationResult
    {
        $result = $this->geoApiGouv->reverse($lat, $lng);
        if ($result !== null) {
            return $result;
        }
        return $this->nominatim->reverse($lat, $lng);
    }

    public function geoApiGouv(): GeoApiGouvReverseGeocoder
    {
        return $this->geoApiGouv;
    }

    public function nominatim(): NominatimReverseGeocoder
    {
        return $this->nominatim;
    }
}
