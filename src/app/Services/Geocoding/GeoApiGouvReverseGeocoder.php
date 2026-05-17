<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverse geocoding via geo.api.gouv.fr (API officielle data.gouv).
 *
 * Endpoint utilisé : `GET /communes?lat=X&lon=Y&fields=...` — fait du
 * **point-in-polygon** sur les communes françaises (métropole + DOM).
 * Contrairement à BAN/reverse qui cherche l'adresse postale la plus
 * proche, cette API regarde simplement dans quelle commune tombe le
 * point. Couvre donc 100% des coordonnées en territoire français,
 * même les sites de parapente en pleine montagne ou en forêt loin de
 * toute adresse indexée par BAN.
 *
 * Pas de rate limit. Renvoie un tableau vide pour les coords hors France
 * — ce qui est notre signal de fallback Nominatim.
 */
class GeoApiGouvReverseGeocoder implements ReverseGeocoderInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {
    }

    public function reverse(float $lat, float $lng): ?LocationResult
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->get($this->baseUrl . '/communes', [
                    'lat'      => $lat,
                    'lon'      => $lng,
                    'fields'   => 'nom,codeDepartement,departement,codeRegion,region',
                    'format'   => 'json',
                    'geometry' => 'centre',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $communes = $response->json();
            if (! is_array($communes) || $communes === []) {
                return null;
            }

            $commune = $communes[0];
            $department  = $commune['departement']['nom'] ?? null;
            $adminRegion = $commune['region']['nom'] ?? null;

            if ($department === null && $adminRegion === null) {
                return null;
            }

            return new LocationResult(
                countryCode: 'FR',
                country: 'France',
                adminRegion: $adminRegion,
                department: $department,
                provider: 'geo-api-gouv',
            );
        } catch (Throwable $e) {
            Log::warning('geo.api.gouv reverse failed', [
                'lat' => $lat, 'lng' => $lng, 'err' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
