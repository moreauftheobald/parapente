<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

interface ReverseGeocoderInterface
{
    /**
     * Reverse geocoding d'un point unique.
     * Retourne null si aucun match exploitable n'est trouvé.
     */
    public function reverse(float $lat, float $lng): ?LocationResult;
}
