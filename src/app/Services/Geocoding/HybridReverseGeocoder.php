<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * Orchestrateur reverse geocoding : BAN d'abord (rapide, illimité,
 * couvre la France), Nominatim en fallback (couvre le reste du monde
 * mais 1 req/s).
 *
 * Pour les sites/balises français (~80% du volume), un seul appel BAN
 * suffit. Pour les ~20% étrangers, on paie un appel BAN inutile +
 * un appel Nominatim — accepté car BAN est gratuit et rapide.
 */
class HybridReverseGeocoder implements ReverseGeocoderInterface
{
    public function __construct(
        private readonly BanReverseGeocoder $ban,
        private readonly NominatimReverseGeocoder $nominatim,
    ) {
    }

    public function reverse(float $lat, float $lng): ?LocationResult
    {
        $result = $this->ban->reverse($lat, $lng);
        if ($result !== null) {
            return $result;
        }
        return $this->nominatim->reverse($lat, $lng);
    }

    public function ban(): BanReverseGeocoder
    {
        return $this->ban;
    }

    public function nominatim(): NominatimReverseGeocoder
    {
        return $this->nominatim;
    }
}
