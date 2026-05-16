<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

/**
 * Catalogue centralisé des règles de validation des `FlyingConditions`
 * (orientation, vitesses, rafales, plafond) — partagé par les requests
 * Store/Update du scoring perso utilisateur.
 *
 * Les invariants relationnels entre champs (min ≤ max, etc.) sont dans
 * `ValidatesScoringCoherence`.
 */
final class ScoringRules
{
    /**
     * Règles communes aux deux requests (création + édition d'un scoring
     * perso). Le `site_id` est ajouté à part par StoreUserScoringRequest
     * car il a une logique d'unicité par user.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function flyingConditions(): array
    {
        return [
            'wind_dir_min'         => ['required', 'integer', 'between:0,360'],
            'wind_dir_max'         => ['required', 'integer', 'between:0,360'],
            'wind_speed_min'       => ['required', 'integer', 'between:0,100'],
            'wind_speed_max'       => ['required', 'integer', 'between:0,100'],
            'wind_speed_ideal'     => ['required', 'integer', 'between:0,100'],
            'wind_gust_orange_kmh' => ['nullable', 'numeric', 'between:0,200'],
            'wind_gust_red_kmh'    => ['nullable', 'numeric', 'between:0,200'],
            'cloud_base_min_m'     => ['nullable', 'integer', 'between:0,5000'],
            'cloud_cover_low_max'  => ['nullable', 'integer', 'between:0,100'],
            'notes'                => ['nullable', 'string', 'max:2000'],
        ];
    }
}
