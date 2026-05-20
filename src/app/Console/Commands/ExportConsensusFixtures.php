<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Weather\Reliability\ConsensusCalculator;
use Illuminate\Console\Command;

/**
 * Exporte les cas de test de `ConsensusCalculator` en JSON, en
 * exécutant réellement le calculateur pour capturer les valeurs
 * numériques exactes (pas seulement les ranges des assertions
 * PHPUnit). Sert de corpus de parité pour le portage Python du
 * sidecar `parapente-consensus-grid`.
 *
 *   php artisan consensus:export-fixtures
 *   php artisan consensus:export-fixtures --output=/tmp/fixtures.json
 *
 * Le fichier produit a une structure stable :
 *   {
 *     "version":       "1.0",
 *     "generated_at":  "2026-05-20T...",
 *     "php_calculator": "App\\Services\\Weather\\Reliability\\ConsensusCalculator",
 *     "cases":         [ { name, method, items, options, expected }, ... ]
 *   }
 *
 * Les tests Python du sidecar chargent ce JSON et vérifient que
 * leur implémentation produit les mêmes `expected` à `rtol=1e-9`.
 *
 * Cf. FF_grid_consensus.md § 6 phase 1a et `tests/fixtures/` du
 * repo `parapente-consensus-grid`.
 */
class ExportConsensusFixtures extends Command
{
    protected $signature = 'consensus:export-fixtures
                            {--output= : chemin du fichier JSON (défaut: storage/app/consensus_fixtures.json)}';

    protected $description = 'Exporte les cas de test ConsensusCalculator en JSON pour le portage Python du sidecar';

    public function handle(): int
    {
        $output = $this->option('output')
            ?: storage_path('app/consensus_fixtures.json');

        $cases = $this->buildCases();
        $exported = [];

        foreach ($cases as $case) {
            try {
                $result = $this->runCase($case);
            } catch (\Throwable $e) {
                $this->error("Cas {$case['name']} échoue : {$e->getMessage()}");
                return self::FAILURE;
            }

            $exported[] = [
                'name'     => $case['name'],
                'method'   => $case['method'],
                'items'    => $case['items']    ?? null,
                'args'     => $case['args']     ?? null,
                'options'  => $case['options']  ?? null,
                'expected' => $result,
                'notes'    => $case['notes']    ?? null,
            ];
        }

        $payload = [
            'version'        => '1.0',
            'generated_at'   => now()->toIso8601String(),
            'php_calculator' => ConsensusCalculator::class,
            'php_version'    => PHP_VERSION,
            'cases'          => $exported,
        ];

        $dir = dirname($output);
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            $this->error('Échec encodage JSON : ' . json_last_error_msg());
            return self::FAILURE;
        }

        file_put_contents($output, $json . "\n");

        $this->info(sprintf('✓ %d cas exportés vers %s', count($exported), $output));
        $this->line('  taille : ' . number_format((float) filesize($output) / 1024, 1) . ' Ko');

        return self::SUCCESS;
    }

    /**
     * Exécute un cas et renvoie le résultat brut (?float pour les
     * méthodes legacy, array pour les improved, float pour les helpers).
     *
     * @param array<string, mixed> $case
     *
     * @return mixed
     */
    private function runCase(array $case): mixed
    {
        $method = $case['method'];

        return match ($method) {
            'legacyLinear'        => ConsensusCalculator::legacyLinear($case['items']),
            'legacyCircular'      => ConsensusCalculator::legacyCircular($case['items']),
            'improvedLinear'      => ConsensusCalculator::improvedLinear($case['items'], $case['options']),
            'improvedCircular'    => ConsensusCalculator::improvedCircular($case['items'], $case['options']),
            'circularDistance'    => ConsensusCalculator::circularDistance(...$case['args']),
            'signedCircularDiff'  => ConsensusCalculator::signedCircularDiff(...$case['args']),
            default               => throw new \InvalidArgumentException("Méthode inconnue : {$method}"),
        };
    }

    /**
     * Construit l'ensemble des cas à exporter. Doit rester aligné
     * avec `tests/Unit/Weather/Reliability/ConsensusCalculatorTest.php`.
     *
     * @return list<array<string, mixed>>
     */
    private function buildCases(): array
    {
        return [
            // ─── Cas du sujet : outliers franches ─────────────────
            [
                'name'    => 'legacy_linear_24_25_26_5_70',
                'method'  => 'legacyLinear',
                'items'   => $this->items([24, 25, 26, 5, 70]),
                'notes'   => '5 modèles dont 2 outliers extrêmes ; legacy reste collé à la médiane grâce à EPSILON 0.001.',
            ],
            [
                'name'    => 'improved_linear_24_25_26_5_70_with_filtering',
                'method'  => 'improvedLinear',
                'items'   => $this->items([24, 25, 26, 5, 70]),
                'options' => $this->improvedOpts(),
                'notes'   => 'Méthode B avec MAD filtering : 5 et 70 doivent être exclus.',
            ],
            [
                'name'    => 'improved_linear_24_25_26_5_70_without_filtering',
                'method'  => 'improvedLinear',
                'items'   => $this->items([24, 25, 26, 5, 70]),
                'options' => $this->improvedOpts(['use_mad_filtering' => false]),
                'notes'   => 'Méthode B sans MAD filtering : les 5 modèles passent, médiane pondérée résiste.',
            ],

            // ─── Cas dégénéré : tous identiques ───────────────────
            [
                'name'    => 'legacy_linear_all_equal',
                'method'  => 'legacyLinear',
                'items'   => $this->items([20, 20, 20, 20]),
            ],
            [
                'name'    => 'improved_linear_all_equal',
                'method'  => 'improvedLinear',
                'items'   => $this->items([20, 20, 20, 20]),
                'options' => $this->improvedOpts(),
                'notes'   => 'MAD raw = 0 → floor 0.5. Aucun outlier filtré.',
            ],

            // ─── Direction qui chevauche Nord ─────────────────────
            [
                'name'    => 'legacy_circular_wrap_north',
                'method'  => 'legacyCircular',
                'items'   => $this->items([350, 355, 5, 10]),
                'notes'   => 'Consensus attendu ≈ 0° (pas 180°). Test du wraparound atan2.',
            ],
            [
                'name'    => 'improved_circular_wrap_north',
                'method'  => 'improvedCircular',
                'items'   => $this->items([350, 355, 5, 10]),
                'options' => $this->improvedOpts(),
            ],

            // ─── Direction avec outlier opposé ────────────────────
            [
                'name'    => 'improved_circular_outlier_opposite',
                'method'  => 'improvedCircular',
                'items'   => $this->items([88, 90, 92, 95, 270]),
                'options' => $this->improvedOpts(),
                'notes'   => '4 modèles à ~90° + 1 à 270° (opposé) → outlier doit être filtré.',
            ],

            // ─── MAD plancher empêche division par zéro ──────────
            [
                'name'    => 'mad_floor_prevents_division_zero',
                'method'  => 'improvedLinear',
                'items'   => $this->items([25, 25, 25, 25, 25, 25.1]),
                'options' => $this->improvedOpts(),
                'notes'   => 'MAD raw ≈ 0 + floor 0.5 ; le 25.1 reste dans le panel.',
            ],

            // ─── Cas limites : liste vide ─────────────────────────
            [
                'name'    => 'empty_legacy_linear',
                'method'  => 'legacyLinear',
                'items'   => [],
                'notes'   => 'Liste vide → null.',
            ],
            [
                'name'    => 'empty_legacy_circular',
                'method'  => 'legacyCircular',
                'items'   => [],
            ],
            [
                'name'    => 'empty_improved_linear',
                'method'  => 'improvedLinear',
                'items'   => [],
                'options' => $this->improvedOpts(),
            ],
            [
                'name'    => 'empty_improved_circular',
                'method'  => 'improvedCircular',
                'items'   => [],
                'options' => $this->improvedOpts(),
            ],

            // ─── Cas limites : un seul modèle ─────────────────────
            [
                'name'    => 'single_legacy_linear',
                'method'  => 'legacyLinear',
                'items'   => $this->items([42]),
            ],
            [
                'name'    => 'single_improved_linear',
                'method'  => 'improvedLinear',
                'items'   => $this->items([42]),
                'options' => $this->improvedOpts(),
            ],

            // ─── Helpers circulaires : circularDistance ───────────
            [
                'name'    => 'circular_distance_355_5',
                'method'  => 'circularDistance',
                'args'    => [355.0, 5.0],
                'notes'   => 'Distance la plus courte = 10°.',
            ],
            [
                'name'    => 'circular_distance_350_10',
                'method'  => 'circularDistance',
                'args'    => [350.0, 10.0],
            ],
            [
                'name'    => 'circular_distance_0_180',
                'method'  => 'circularDistance',
                'args'    => [0.0, 180.0],
                'notes'   => 'Cas pathologique : maximum 180°.',
            ],
            [
                'name'    => 'circular_distance_90_0',
                'method'  => 'circularDistance',
                'args'    => [90.0, 0.0],
            ],
            [
                'name'    => 'circular_distance_identical',
                'method'  => 'circularDistance',
                'args'    => [45.0, 45.0],
                'notes'   => 'Angles identiques → 0.',
            ],

            // ─── Helpers circulaires : signedCircularDiff ────────
            [
                'name'    => 'signed_circular_diff_pred_after_obs',
                'method'  => 'signedCircularDiff',
                'args'    => [10.0, 350.0],
                'notes'   => 'Prédiction sens horaire après obs → positif.',
            ],
            [
                'name'    => 'signed_circular_diff_pred_after_obs_simple',
                'method'  => 'signedCircularDiff',
                'args'    => [20.0, 10.0],
            ],
            [
                'name'    => 'signed_circular_diff_pred_before_obs',
                'method'  => 'signedCircularDiff',
                'args'    => [350.0, 10.0],
                'notes'   => 'Prédiction sens trigo avant obs → négatif.',
            ],
            [
                'name'    => 'signed_circular_diff_pred_before_obs_simple',
                'method'  => 'signedCircularDiff',
                'args'    => [10.0, 20.0],
            ],
            [
                'name'    => 'signed_circular_diff_zero',
                'method'  => 'signedCircularDiff',
                'args'    => [45.0, 45.0],
            ],
            [
                'name'    => 'signed_circular_diff_180',
                'method'  => 'signedCircularDiff',
                'args'    => [0.0, 180.0],
                'notes'   => 'Limite ambiguë ±180. Convention PHP : -180.',
            ],

            // ─── Pondération par fiabilité (méthode C) ───────────
            [
                'name'    => 'reliability_weighting_uniform',
                'method'  => 'improvedLinear',
                'items'   => $this->items([22, 24, 26, 28], 1.0),
                'options' => $this->improvedOpts([
                    'use_weighted_median' => false,
                    'use_mad_filtering'   => false,
                ]),
                'notes'   => 'B avec poids uniformes : référence pour comparaison.',
            ],
            [
                'name'    => 'reliability_weighting_skewed_high',
                'method'  => 'improvedLinear',
                'items'   => [
                    ['value' => 22.0, 'weight' => 0.25],
                    ['value' => 24.0, 'weight' => 0.25],
                    ['value' => 26.0, 'weight' => 2.00],
                    ['value' => 28.0, 'weight' => 2.00],
                ],
                'options' => $this->improvedOpts([
                    'use_weighted_median' => false,
                    'use_mad_filtering'   => false,
                ]),
                'notes'   => 'C avec sur-poids sur valeurs hautes : consensus tiré vers 26+ ; vérifier C > B.',
            ],
        ];
    }

    /**
     * Helper aligné sur `ConsensusCalculatorTest::items()`.
     *
     * @param  array<int|float> $values
     * @return list<array{value:float,weight:float}>
     */
    private function items(array $values, float $weight = 1.0): array
    {
        return array_values(array_map(
            static fn ($v) => ['value' => (float) $v, 'weight' => $weight],
            $values
        ));
    }

    /**
     * Helper aligné sur `ConsensusCalculatorTest::improvedOpts()`.
     *
     * @param  array<string, mixed> $overrides
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
}
