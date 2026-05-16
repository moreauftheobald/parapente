<?php

declare(strict_types=1);

namespace App\Services\Balises;

use App\Models\BaliseReading;

/**
 * Sérialisation des lectures de balises pour les payloads JSON
 * (`/api/balises` + `/api/balises/{id}/history`).
 *
 * Les colonnes Eloquent sont stockées en DECIMAL → string par défaut.
 * On les caste explicitement en float (avec préservation des null) pour
 * que le JSON émis soit homogène côté front.
 */
final class BaliseReadingFormatter
{
    /**
     * Champs météo communs d'une lecture (sans `read_at`, ajouté par
     * l'appelant en fonction du contexte : ISO 8601 + variantes
     * `time`, `min_of_day`…).
     *
     * @return array<string,mixed>
     */
    public static function meteorology(BaliseReading $r): array
    {
        return [
            'wind_direction' => $r->wind_direction,
            'wind_speed_avg' => self::floatOrNull($r->wind_speed_avg),
            'wind_speed_min' => self::floatOrNull($r->wind_speed_min),
            'wind_speed_max' => self::floatOrNull($r->wind_speed_max),
            'temperature'    => self::floatOrNull($r->temperature),
            'humidity'       => $r->humidity,
        ];
    }

    public static function floatOrNull(mixed $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }
}
