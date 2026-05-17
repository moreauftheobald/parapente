<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * Résultat d'un reverse geocoding (BAN ou Nominatim).
 *
 * Tous les champs administratifs sont nullable — selon le pays et le
 * provider, certains niveaux n'existent pas (le Luxembourg n'a pas de
 * département au sens français, certains Bundesländer allemands n'ont
 * pas de Landkreis, etc.).
 */
final readonly class LocationResult
{
    public function __construct(
        public string $countryCode,         // ISO-2 uppercase (FR, BE, DE…)
        public ?string $country,            // Libellé en français
        public ?string $adminRegion,        // Région / Bundesland / canton…
        public ?string $department,         // Département / province / Landkreis…
        public string $provider,            // 'ban' | 'nominatim'
        public ?float $score = null,        // Score de confiance brut du provider
    ) {
    }
}
