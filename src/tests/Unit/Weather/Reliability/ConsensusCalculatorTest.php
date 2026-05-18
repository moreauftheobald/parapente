<?php

declare(strict_types=1);

namespace Tests\Unit\Weather\Reliability;

use App\Services\Weather\Reliability\ConsensusCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires des trois variantes de consensus (phase 2.5).
 *
 * Pas de RefreshDatabase : ConsensusCalculator est une classe pure.
 */
class ConsensusCalculatorTest extends TestCase
{
    /**
     * Options par défaut pour les variantes améliorées (B et C).
     *
     * @return array<string, mixed>
     */
    private function improvedOpts(array $overrides = []): array
    {
        return array_merge([
            'epsilon'             => 1.0,
            'mad_floor'           => 0.5,
            'z_threshold'         => 3.0,
            'use_weighted_median' => true,
            'use_mad_filtering'   => true,
        ], $overrides);
    }

    /** @param array<float> $values @return array<array{value:float,weight:float}> */
    private function items(array $values, float $weight = 1.0): array
    {
        return array_map(
            static fn ($v) => ['value' => (float) $v, 'weight' => $weight],
            $values
        );
    }

    // ─── Cas du sujet : 24/25/26/5/70 ──────────────────────────────
    // 4 modèles cohérents autour de 25 + 1 outlier bas (5) + 1 outlier haut (70).
    // Médiane = 25. MAD = médiane des |v - 25| = médiane(1, 0, 1, 20, 45) = 1.
    // Avec MAD_floor = 0.5, MAD effectif = max(1, 0.5) = 1.
    // sigma_robust = 1 × 1.4826 = 1.4826.
    // z pour 5  → |5-25|/1.4826 = 13.5  > 3  → exclu
    // z pour 70 → |70-25|/1.4826 = 30.4 > 3  → exclu
    // Reste {24, 25, 26}, agrégation pondérée par inverse-carré sur écart à médiane.

    public function test_legacy_linear_reste_pres_de_la_mediane(): void
    {
        $items = $this->items([24, 25, 26, 5, 70]);
        $a = ConsensusCalculator::legacyLinear($items);

        // Médiane = 25. EPSILON 0.001 donne un poids énorme à la valeur
        // pile sur la médiane (poids ≈ 1000). Les 24/26 à distance 1 ont
        // un poids ≈ 1. Le 5 et le 70 ont des poids minuscules.
        // Conséquence : A reste collé à la médiane, mais c'est précisément
        // le cas où ce comportement est "chanceux" — si aucun modèle
        // n'était pile à 25, l'algo serait plus exposé aux outliers.
        $this->assertNotNull($a);
        $this->assertGreaterThan(20.0, $a, "Consensus A doit rester proche du cluster central");
        $this->assertLessThan(30.0, $a);
    }

    public function test_improved_linear_rejette_outliers(): void
    {
        $items = $this->items([24, 25, 26, 5, 70]);
        $b = ConsensusCalculator::improvedLinear($items, $this->improvedOpts());

        // 2 outliers filtrés (5 et 70), reste 3 modèles
        $this->assertSame(3, $b['models_kept']);
        $this->assertSame(2, $b['models_filtered']);

        // Consensus doit tomber dans le cluster [24, 26]
        $this->assertNotNull($b['value']);
        $this->assertGreaterThanOrEqual(24.0, $b['value']);
        $this->assertLessThanOrEqual(26.0, $b['value']);

        // MAD brute = médiane des |v - 25| = médiane(1, 0, 1, 20, 45) → 1
        $this->assertEqualsWithDelta(1.0, $b['mad_value'], 0.01);
    }

    public function test_improved_linear_sans_filtrage_mad(): void
    {
        $items = $this->items([24, 25, 26, 5, 70]);
        $b = ConsensusCalculator::improvedLinear(
            $items,
            $this->improvedOpts(['use_mad_filtering' => false])
        );

        // Tous les modèles passent
        $this->assertSame(5, $b['models_kept']);
        $this->assertSame(0, $b['models_filtered']);

        // Avec EPSILON = 1.0 et médiane pondérée, l'outlier 70 ne tire
        // PAS le consensus loin de 25 (la médiane pondérée résiste mieux
        // que la moyenne pondérée).
        $this->assertNotNull($b['value']);
        $this->assertGreaterThanOrEqual(24.0, $b['value']);
        $this->assertLessThanOrEqual(26.0, $b['value']);
    }

    // ─── Cas dégénéré : tous identiques ────────────────────────────

    public function test_tous_modeles_identiques(): void
    {
        $items = $this->items([20, 20, 20, 20]);

        $a = ConsensusCalculator::legacyLinear($items);
        $b = ConsensusCalculator::improvedLinear($items, $this->improvedOpts());

        $this->assertEqualsWithDelta(20.0, $a, 0.001);
        $this->assertEqualsWithDelta(20.0, $b['value'], 0.001);

        // MAD raw = 0 (toutes valeurs identiques). MAD effectif = floor 0.5.
        // Aucun outlier filtré.
        $this->assertEqualsWithDelta(0.0, $b['mad_value'], 0.001);
        $this->assertSame(4, $b['models_kept']);
        $this->assertSame(0, $b['models_filtered']);
    }

    // ─── Direction circulaire qui chevauche Nord ──────────────────

    public function test_direction_chevauchant_nord(): void
    {
        // 350, 355, 5, 10 → consensus attendu ≈ 0° (pas 180°)
        $items = $this->items([350, 355, 5, 10]);

        $a = ConsensusCalculator::legacyCircular($items);
        $b = ConsensusCalculator::improvedCircular($items, $this->improvedOpts());

        // Pour atan2 avec angles symétriques autour de 0, la résultante
        // est à 0° (±360 wrap). On accepte [355, 5] avec normalisation.
        $this->assertNotNull($a);
        $aNorm = $a > 180 ? $a - 360 : $a;
        $this->assertEqualsWithDelta(0.0, $aNorm, 0.5);

        $this->assertNotNull($b['value']);
        $bNorm = $b['value'] > 180 ? $b['value'] - 360 : $b['value'];
        $this->assertEqualsWithDelta(0.0, $bNorm, 0.5);
    }

    public function test_direction_filtrage_outlier(): void
    {
        // 4 modèles à ~90° (Est) + 1 modèle qui annonce 270° (Ouest, totalement opposé).
        $items = $this->items([88, 90, 92, 95, 270]);

        $b = ConsensusCalculator::improvedCircular($items, $this->improvedOpts());

        // Le 270° doit être filtré comme outlier circulaire
        $this->assertSame(4, $b['models_kept']);
        $this->assertSame(1, $b['models_filtered']);

        $this->assertNotNull($b['value']);
        $this->assertEqualsWithDelta(91.25, $b['value'], 2.0); // ≈ moyenne des 4 restants
    }

    // ─── Cold start : weight_factor neutre → C ≡ B ─────────────────

    public function test_cold_start_C_equivaut_B(): void
    {
        // Tous les modèles avec weight = 1.0 (= cold start, model_reliability vide)
        $items = $this->items([24, 25, 26, 5, 70], 1.0);

        $b = ConsensusCalculator::improvedLinear($items, $this->improvedOpts());
        $c = ConsensusCalculator::improvedLinear($items, $this->improvedOpts());

        $this->assertEqualsWithDelta($b['value'], $c['value'], 0.0001);
        $this->assertSame($b['models_kept'], $c['models_kept']);
    }

    // ─── MAD plancher empêche division par zéro ────────────────────

    public function test_mad_plancher_evite_division_zero(): void
    {
        // 5 modèles convergent à 25 + 1 quasi-doublon à 25.1
        $items = $this->items([25, 25, 25, 25, 25, 25.1]);

        // MAD brute ≈ 0 (médiane des écarts = 0). Sans plancher, sigma_robust = 0
        // et tout |v - médiane| > 0 serait filtré → division par zéro évitée.
        // Avec plancher 0.5, sigma_robust ≈ 0.74. Distance(25.1, 25) = 0.1 →
        // z = 0.13 < 3 → 25.1 reste dans le panel.

        $b = ConsensusCalculator::improvedLinear($items, $this->improvedOpts());

        $this->assertNotNull($b['value']);
        $this->assertSame(6, $b['models_kept']);
        $this->assertSame(0, $b['models_filtered']);
        $this->assertEqualsWithDelta(0.0, $b['mad_value'], 0.01);
    }

    // ─── Cas limites ───────────────────────────────────────────────

    public function test_liste_vide_retourne_null(): void
    {
        $this->assertNull(ConsensusCalculator::legacyLinear([]));
        $this->assertNull(ConsensusCalculator::legacyCircular([]));

        $linear = ConsensusCalculator::improvedLinear([], $this->improvedOpts());
        $this->assertNull($linear['value']);
        $this->assertSame(0, $linear['models_kept']);

        $circular = ConsensusCalculator::improvedCircular([], $this->improvedOpts());
        $this->assertNull($circular['value']);
    }

    public function test_un_seul_modele_retourne_sa_valeur(): void
    {
        $items = $this->items([42]);

        $this->assertEqualsWithDelta(42.0, ConsensusCalculator::legacyLinear($items), 0.001);

        $b = ConsensusCalculator::improvedLinear($items, $this->improvedOpts());
        $this->assertEqualsWithDelta(42.0, $b['value'], 0.001);
    }

    public function test_distance_circulaire_correcte(): void
    {
        $this->assertEqualsWithDelta(10.0, ConsensusCalculator::circularDistance(355, 5), 0.001);
        $this->assertEqualsWithDelta(20.0, ConsensusCalculator::circularDistance(350, 10), 0.001);
        $this->assertEqualsWithDelta(180.0, ConsensusCalculator::circularDistance(0, 180), 0.001);
        $this->assertEqualsWithDelta(90.0, ConsensusCalculator::circularDistance(90, 0), 0.001);
        $this->assertEqualsWithDelta(0.0, ConsensusCalculator::circularDistance(45, 45), 0.001);
    }

    public function test_diff_circulaire_signee(): void
    {
        // Prédiction "après" l'observation dans le sens horaire → positif
        $this->assertEqualsWithDelta(20.0, ConsensusCalculator::signedCircularDiff(10, 350), 0.001);
        $this->assertEqualsWithDelta(10.0, ConsensusCalculator::signedCircularDiff(20, 10), 0.001);

        // Prédiction "avant" l'observation → négatif
        $this->assertEqualsWithDelta(-20.0, ConsensusCalculator::signedCircularDiff(350, 10), 0.001);
        $this->assertEqualsWithDelta(-10.0, ConsensusCalculator::signedCircularDiff(10, 20), 0.001);

        // Exact → 0
        $this->assertEqualsWithDelta(0.0, ConsensusCalculator::signedCircularDiff(45, 45), 0.001);

        // Limites ambiguës ±180
        $this->assertEqualsWithDelta(-180.0, ConsensusCalculator::signedCircularDiff(0, 180), 0.001);
    }

    // ─── Pondération par fiabilité (consensus C) ───────────────────

    public function test_fiabilite_dynamique_influe_sur_C_en_mode_moyenne(): void
    {
        // Important sur le DESIGN : la médiane pondérée + inverse-carré
        // atténuent fortement l'effet du weight_factor (la majorité
        // numérique l'emporte presque toujours). Pour rendre l'effet
        // visible, il faut soit (a) un dataset où les valeurs sont
        // proches de la médiane, soit (b) désactiver `use_weighted_median`.
        //
        // Ce test illustre l'effet en MODE MOYENNE PONDÉRÉE
        // (`use_weighted_median => false`) — c'est la configuration où
        // weight_factor reprend toute sa puissance discriminante. À
        // surveiller en phase 2.5 : si C ≡ B trop souvent avec la
        // médiane pondérée, basculer ce switch.

        // 4 modèles dans le voisinage [22, 24, 26, 28].
        // Médiane (convention values[floor(n/2)]) = values[2] = 26.
        $itemsB = $this->items([22, 24, 26, 28], 1.0);
        $itemsC = [
            ['value' => 22.0, 'weight' => 0.25],  // modèles "bas" peu fiables
            ['value' => 24.0, 'weight' => 0.25],
            ['value' => 26.0, 'weight' => 2.00],  // modèles "haut" très fiables
            ['value' => 28.0, 'weight' => 2.00],
        ];

        // Moyenne pondérée + filtrage MAD désactivé (pour isoler l'effet poids)
        $opts = $this->improvedOpts([
            'use_weighted_median' => false,
            'use_mad_filtering'   => false,
        ]);

        $b = ConsensusCalculator::improvedLinear($itemsB, $opts);
        $c = ConsensusCalculator::improvedLinear($itemsC, $opts);

        $this->assertNotNull($b['value']);
        $this->assertNotNull($c['value']);

        // B (poids égaux) tombe autour de 25.8 (tiré vers la médiane = 26).
        $this->assertEqualsWithDelta(25.8, $b['value'], 0.5, 'B doit rester proche de la médiane');

        // C tire plus haut (vers 26+) grâce au sur-poids des modèles haut.
        $this->assertGreaterThan(
            $b['value'] + 0.2,
            $c['value'],
            'C doit tirer plus haut que B grâce au weight_factor sur les modèles haut'
        );
    }
}
