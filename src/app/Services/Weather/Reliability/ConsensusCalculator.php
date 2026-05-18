<?php

declare(strict_types=1);

namespace App\Services\Weather\Reliability;

/**
 * Calculs de consensus multi-modèles pour la phase 2.5 (shadow comparatif).
 *
 * Trois variantes calculées en parallèle puis confrontées à la vérité
 * terrain (lecture balise) :
 *  - A : algorithme legacy — moyenne pondérée inverse-carré avec
 *        EPSILON 0.001 (vitesses), moyenne circulaire pondérée (direction).
 *        Reproduction stricte du calcul fait par `ScoringService` en prod.
 *  - B : amélioré sans fiabilité — EPSILON revu, filtrage MAD intra-créneau,
 *        médiane pondérée (vitesses) / moyenne circulaire pondérée
 *        post-filtrage (direction). Toutes les fiabilités à 1.0.
 *  - C : amélioré avec fiabilité — identique à B mais les poids initiaux
 *        intègrent `weight_factor` issu de `model_reliability`.
 *
 * Classe pure et statique : pas d'état, pas d'I/O. Les options et les
 * poids sont passés en paramètre par l'appelant (qui a déjà résolu les
 * settings et la fiabilité). Facilite les tests unitaires.
 *
 * Convention pour `$items` :
 *   [
 *     ['value' => float, 'weight' => float, 'model_id' => int|null],
 *     ...
 *   ]
 * Le `model_id` est optionnel (debug). Le `weight` représente le poids
 * initial : 1.0 pour A et B, weight_factor du modèle pour C.
 *
 * Convention pour la direction : `value` en degrés [0, 360[.
 *
 * Cf. FF_model_reliability.md (section phase 2.5).
 */
final class ConsensusCalculator
{
    public const EPSILON_LEGACY = 0.001;

    /**
     * Consensus A (legacy linéaire) — reproduit `ScoringService::weightedMeanInverseSquare`.
     *
     * Retourne null si la liste est vide.
     */
    public static function legacyLinear(array $items): ?float
    {
        if ($items === []) {
            return null;
        }
        if (count($items) === 1) {
            return (float) $items[0]['value'];
        }

        $values = array_map(static fn ($i) => (float) $i['value'], $items);
        $median = self::median($values);

        $totalWeight = 0.0;
        $weightedSum = 0.0;

        foreach ($items as $item) {
            $distance      = abs((float) $item['value'] - $median);
            $inverseSquare = 1.0 / (($distance ** 2) + self::EPSILON_LEGACY);
            $finalWeight   = (float) $item['weight'] * $inverseSquare;

            $weightedSum += (float) $item['value'] * $finalWeight;
            $totalWeight += $finalWeight;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : $median;
    }

    /**
     * Consensus A (legacy circulaire) — moyenne circulaire pondérée par
     * résultante vectorielle (atan2 sur Σ sin / Σ cos).
     *
     * Retourne null si la liste est vide ou si la résultante est nulle
     * (modèles parfaitement opposés — situation pathologique).
     */
    public static function legacyCircular(array $items): ?float
    {
        if ($items === []) {
            return null;
        }

        $sinSum = 0.0;
        $cosSum = 0.0;
        $totalW = 0.0;

        foreach ($items as $item) {
            $rad = deg2rad((float) $item['value']);
            $w   = (float) $item['weight'];
            $sinSum += sin($rad) * $w;
            $cosSum += cos($rad) * $w;
            $totalW += $w;
        }

        if ($totalW === 0.0) {
            return null;
        }

        $mean = rad2deg(atan2($sinSum / $totalW, $cosSum / $totalW));

        return $mean < 0 ? $mean + 360.0 : $mean;
    }

    /**
     * Consensus B / C linéaire (amélioré).
     *
     * @param array<array{value:float|int,weight:float|int,model_id?:int|null}> $items
     * @param array{
     *     epsilon: float,
     *     mad_floor: float,
     *     z_threshold: float,
     *     use_weighted_median: bool,
     *     use_mad_filtering: bool
     * } $opts
     *
     * @return array{value: float|null, models_kept: int, models_filtered: int, mad_value: float|null}
     */
    public static function improvedLinear(array $items, array $opts): array
    {
        if ($items === []) {
            return ['value' => null, 'models_kept' => 0, 'models_filtered' => 0, 'mad_value' => null];
        }

        $values   = array_map(static fn ($i) => (float) $i['value'], $items);
        $median   = self::median($values);
        $madRaw   = self::mad($values, $median);                       // MAD brute
        $madValue = max($madRaw, $opts['mad_floor']);                  // après plancher

        // 1. Filtrage MAD (optionnel)
        $kept     = [];
        $filtered = 0;
        if ($opts['use_mad_filtering']) {
            // Échelle robuste équivalente écart-type : MAD × 1.4826
            $sigmaRobust = $madValue * 1.4826;
            foreach ($items as $item) {
                $z = $sigmaRobust > 0 ? abs((float) $item['value'] - $median) / $sigmaRobust : 0.0;
                if ($z <= $opts['z_threshold']) {
                    $kept[] = $item;
                } else {
                    $filtered++;
                }
            }
            // Garde-fou : si tout est filtré (improbable), on revient sur l'ensemble brut
            if ($kept === []) {
                $kept     = $items;
                $filtered = 0;
            }
        } else {
            $kept = $items;
        }

        // 2. Agrégation pondérée (médiane ou moyenne, inverse-carré par-dessus)
        if ($opts['use_weighted_median']) {
            $value = self::weightedMedianInverseSquare($kept, $median, $opts['epsilon']);
        } else {
            $value = self::weightedMeanInverseSquare($kept, $median, $opts['epsilon']);
        }

        return [
            'value'           => $value,
            'models_kept'     => count($kept),
            'models_filtered' => $filtered,
            'mad_value'       => $madRaw,
        ];
    }

    /**
     * Consensus B / C circulaire (amélioré).
     *
     * Note (cf. FF, section *Risques* §8) : on utilise une moyenne
     * circulaire pondérée (résultante vectorielle) au lieu d'une vraie
     * médiane circulaire pondérée. La robustesse vient du filtrage MAD
     * circulaire **avant** agrégation. Suffisant pour des données quasi
     * unimodales — bimodalités fortes à surveiller via `mad_value`.
     *
     * @param array<array{value:float|int,weight:float|int,model_id?:int|null}> $items
     * @param array{
     *     mad_floor: float,
     *     z_threshold: float,
     *     use_mad_filtering: bool
     * } $opts
     *
     * @return array{value: float|null, models_kept: int, models_filtered: int, mad_value: float|null}
     */
    public static function improvedCircular(array $items, array $opts): array
    {
        if ($items === []) {
            return ['value' => null, 'models_kept' => 0, 'models_filtered' => 0, 'mad_value' => null];
        }

        $values  = array_map(static fn ($i) => (float) $i['value'], $items);
        $median  = self::circularMedian($values);
        $madRaw  = self::circularMad($values, $median);                // en degrés
        $madDeg  = max($madRaw, $opts['mad_floor']);

        // Filtrage MAD circulaire
        $kept     = [];
        $filtered = 0;
        if ($opts['use_mad_filtering']) {
            $thresholdDeg = $opts['z_threshold'] * $madDeg;
            foreach ($items as $item) {
                $d = self::circularDistance((float) $item['value'], $median);
                if ($d <= $thresholdDeg) {
                    $kept[] = $item;
                } else {
                    $filtered++;
                }
            }
            if ($kept === []) {
                $kept     = $items;
                $filtered = 0;
            }
        } else {
            $kept = $items;
        }

        // Agrégation : moyenne circulaire pondérée sur l'ensemble retenu.
        $value = self::legacyCircular($kept);

        return [
            'value'           => $value,
            'models_kept'     => count($kept),
            'models_filtered' => $filtered,
            'mad_value'       => $madRaw,
        ];
    }

    // ── Helpers internes ───────────────────────────────────────────

    /**
     * Médiane simple (ordinale, sans interpolation pour n pair —
     * cohérent avec `ScoringService`).
     *
     * @param array<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        return $values[(int) floor(count($values) / 2)];
    }

    /**
     * MAD (Median Absolute Deviation) — médiane des écarts absolus à la médiane.
     *
     * @param array<float> $values
     */
    private static function mad(array $values, float $median): float
    {
        $deviations = array_map(static fn ($v) => abs($v - $median), $values);
        return self::median($deviations);
    }

    /**
     * Moyenne pondérée par inverse du carré de l'écart à la médiane,
     * EPSILON paramétrable.
     *
     * @param array<array{value:float|int,weight:float|int}> $items
     */
    private static function weightedMeanInverseSquare(array $items, float $median, float $epsilon): float
    {
        if (count($items) === 1) {
            return (float) $items[0]['value'];
        }

        $totalWeight = 0.0;
        $weightedSum = 0.0;
        foreach ($items as $item) {
            $distance      = abs((float) $item['value'] - $median);
            $inverseSquare = 1.0 / (($distance ** 2) + $epsilon);
            $w             = (float) $item['weight'] * $inverseSquare;

            $weightedSum += (float) $item['value'] * $w;
            $totalWeight += $w;
        }
        return $totalWeight > 0 ? $weightedSum / $totalWeight : $median;
    }

    /**
     * Médiane pondérée. Les poids initiaux sont combinés avec un
     * inverse-carré sur l'écart à la médiane brute (même esprit que
     * la moyenne pondérée mais en sélectionnant la valeur médiane).
     *
     * Algorithme : tri des items par valeur croissante, recherche du
     * point où le cumul des poids atteint W/2.
     *
     * @param array<array{value:float|int,weight:float|int}> $items
     */
    private static function weightedMedianInverseSquare(array $items, float $median, float $epsilon): float
    {
        if (count($items) === 1) {
            return (float) $items[0]['value'];
        }

        // Combine poids initial × inverse-carré sur l'écart à la médiane
        $weighted = array_map(static function ($item) use ($median, $epsilon) {
            $distance      = abs((float) $item['value'] - $median);
            $inverseSquare = 1.0 / (($distance ** 2) + $epsilon);
            return [
                'value'  => (float) $item['value'],
                'weight' => (float) $item['weight'] * $inverseSquare,
            ];
        }, $items);

        usort($weighted, static fn ($a, $b) => $a['value'] <=> $b['value']);
        $totalWeight = array_sum(array_column($weighted, 'weight'));
        if ($totalWeight <= 0) {
            return $median;
        }

        $half   = $totalWeight / 2.0;
        $cumul  = 0.0;
        $picked = $weighted[0]['value'];
        foreach ($weighted as $w) {
            $cumul += $w['weight'];
            if ($cumul >= $half) {
                $picked = $w['value'];
                break;
            }
        }
        return $picked;
    }

    /**
     * Médiane circulaire (non pondérée) — recherche du θ ∈ {θ_i} qui
     * minimise Σ d(θ, θ_i) avec d = distance angulaire absolue.
     * Suffisant pour quelques modèles (recherche O(n²)).
     *
     * @param array<float> $values
     */
    private static function circularMedian(array $values): float
    {
        $best     = $values[0];
        $bestCost = INF;
        foreach ($values as $candidate) {
            $cost = 0.0;
            foreach ($values as $v) {
                $cost += self::circularDistance($candidate, $v);
            }
            if ($cost < $bestCost) {
                $bestCost = $cost;
                $best     = $candidate;
            }
        }
        return $best;
    }

    /**
     * Distance angulaire absolue entre deux directions (en degrés).
     * Retourne une valeur dans [0, 180].
     */
    public static function circularDistance(float $a, float $b): float
    {
        $d = fmod(abs($a - $b), 360.0);
        return $d > 180.0 ? 360.0 - $d : $d;
    }

    /**
     * Différence circulaire signée entre une prédiction et une observation
     * (en degrés, dans [-180, 180]).
     *
     * Positif = la prédiction est "après" l'observation dans le sens horaire
     * (sur-rotation). Négatif = avant.
     *
     * Utile pour le bias_signed dans la table model_reliability : permet
     * de détecter un biais systématique de direction d'un modèle.
     */
    public static function signedCircularDiff(float $pred, float $obs): float
    {
        return fmod($pred - $obs + 540.0, 360.0) - 180.0;
    }

    /**
     * MAD circulaire — médiane des distances angulaires à la médiane circulaire.
     *
     * @param array<float> $values
     */
    private static function circularMad(array $values, float $median): float
    {
        $deviations = array_map(
            static fn ($v) => self::circularDistance($v, $median),
            $values
        );
        return self::median($deviations);
    }
}
