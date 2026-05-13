<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Validation\Validator;

/**
 * Règles de cohérence transverses partagées par Store/Update du scoring perso.
 * Les bornes individuelles (between:0,…) sont déjà couvertes par les rules
 * de base ; on vérifie ici les invariants relationnels entre champs.
 */
final class ValidatesScoringCoherence
{
    /**
     * @param array<string,mixed> $data
     */
    public static function checkRanges(Validator $v, array $data): void
    {
        $get = fn (string $k): mixed => $data[$k] ?? null;

        // Vitesses : min ≤ ideal ≤ max
        $min   = is_numeric($get('wind_speed_min'))   ? (int) $get('wind_speed_min')   : null;
        $max   = is_numeric($get('wind_speed_max'))   ? (int) $get('wind_speed_max')   : null;
        $ideal = is_numeric($get('wind_speed_ideal')) ? (int) $get('wind_speed_ideal') : null;

        if ($min !== null && $max !== null && $min > $max) {
            $v->errors()->add('wind_speed_max', 'La vitesse maximale doit être ≥ à la vitesse minimale.');
        }
        if ($min !== null && $ideal !== null && $ideal < $min) {
            $v->errors()->add('wind_speed_ideal', 'La vitesse idéale doit être ≥ à la vitesse minimale.');
        }
        if ($max !== null && $ideal !== null && $ideal > $max) {
            $v->errors()->add('wind_speed_ideal', 'La vitesse idéale doit être ≤ à la vitesse maximale.');
        }

        // Rafales : orange < red (si les deux sont fournies)
        $gustOrange = is_numeric($get('wind_gust_orange_kmh')) ? (float) $get('wind_gust_orange_kmh') : null;
        $gustRed    = is_numeric($get('wind_gust_red_kmh'))    ? (float) $get('wind_gust_red_kmh')    : null;
        if ($gustOrange !== null && $gustRed !== null && $gustOrange >= $gustRed) {
            $v->errors()->add('wind_gust_red_kmh', 'Le seuil rouge doit être strictement supérieur au seuil orange.');
        }
    }
}
