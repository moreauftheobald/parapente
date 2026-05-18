<?php

declare(strict_types=1);

namespace App\Services\Weather\Reliability;

use App\Models\Balise;
use App\Models\BaliseConsensusCompare;
use App\Models\BaliseReadingHourly;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Orchestre le calcul des 3 consensus (A legacy, B amélioré, C avec
 * fiabilité) pour les balises du panel de validation (phase 2.5).
 *
 * Pour chaque balise marquée `in_consensus_compare_panel = true` et
 * chaque créneau dans la fenêtre demandée, on calcule trois consensus
 * pour chacune des 3 variables (avg / max / dir), on les confronte à
 * la vérité-terrain (lecture balise agrégée à l'heure) et on persiste
 * le tuple dans `balise_consensus_compare`.
 *
 * Le job qui appelle ce service est dans le commit suivant
 * (`ComputeBaliseConsensusCompareJob`).
 *
 * Cf. FF_model_reliability.md (section phase 2.5).
 */
class BaliseConsensusCompareService
{
    /**
     * Les 3 variables comparées en phase 2.5. La direction utilise un
     * algorithme circulaire (cf. ConsensusCalculator::improvedCircular),
     * les vitesses utilisent un algorithme linéaire.
     */
    public const VARIABLES = ['wind_speed_avg', 'wind_speed_max', 'wind_direction'];

    public function __construct(
        private Settings $settings,
        private ReliabilityCalculator $reliability,
    ) {
    }

    /**
     * Recalcule les consensus pour une balise sur une fenêtre donnée.
     *
     * Idempotent : ré-exécutable à volonté, upsert sur la clé unique
     * (balise_id, target_at, horizon_bucket, variable).
     *
     * Honore le kill switch global `reliability.shadow_enabled` —
     * retourne 0 immédiatement si désactivé.
     *
     * Retourne le nombre de tuples (variable × créneau) traités.
     */
    public function computeForBalise(Balise $balise, CarbonInterface $from, CarbonInterface $to): int
    {
        if (! (bool) $this->settings->get('reliability.shadow_enabled', true)) {
            return 0;
        }
        if (! $balise->in_consensus_compare_panel) {
            return 0;
        }

        $opts = $this->resolveOptions();

        // 1. Charge tous les forecasts archivés sur la fenêtre.
        $forecasts = $this->loadForecasts($balise->id, $from, $to);
        if ($forecasts === []) {
            return 0;
        }

        // 2. Charge en une requête toutes les observations balises de la fenêtre.
        $observations = $this->loadObservations($balise->id, $from, $to);

        // 3. Pré-calcule les weight_factor (un batch par (bucket × variable)).
        $factors = $this->preloadWeightFactors($balise->id, $forecasts);

        // 4. Itère sur chaque (target_at, bucket) et calcule les 3 consensus
        //    pour chacune des 3 variables.
        $now     = CarbonImmutable::now();
        $written = 0;
        foreach ($forecasts as $groupKey => $rows) {
            [$targetAt, $bucket] = explode('|', $groupKey, 2);
            $obsRow = $observations[$targetAt] ?? null;

            foreach (self::VARIABLES as $variable) {
                $itemsBase = $this->buildItems($rows, $variable);
                if ($itemsBase === []) {
                    continue;
                }
                $itemsC = $this->applyReliabilityWeights($itemsBase, $factors, $bucket, $variable);

                $result = $this->computeTriple($itemsBase, $itemsC, $variable, $opts);

                $observation      = $this->observationFor($obsRow, $variable);
                $observationCount = $obsRow?->readings_count;

                BaliseConsensusCompare::query()->updateOrCreate(
                    [
                        'balise_id'      => $balise->id,
                        'target_at'      => $targetAt,
                        'horizon_bucket' => $bucket,
                        'variable'       => $variable,
                    ],
                    [
                        'consensus_a'       => $result['a'],
                        'consensus_b'       => $result['b']['value'],
                        'consensus_c'       => $result['c']['value'],
                        'observation'       => $observation,
                        'observation_count' => $observationCount,
                        'models_count'      => count($itemsBase),
                        'mad_value'         => $result['b']['mad_value'],
                        'computed_at'       => $now,
                    ]
                );
                $written++;
            }
        }

        return $written;
    }

    /**
     * Calcule les 3 consensus pour une variable donnée.
     *
     * @return array{a: float|null, b: array, c: array}
     */
    private function computeTriple(array $itemsBase, array $itemsC, string $variable, array $opts): array
    {
        if ($variable === 'wind_direction') {
            $a = ConsensusCalculator::legacyCircular($itemsBase);
            $b = ConsensusCalculator::improvedCircular($itemsBase, [
                'mad_floor'         => $opts['dir_mad_floor'],
                'z_threshold'       => $opts['dir_z_threshold'],
                'use_mad_filtering' => $opts['use_mad_filtering'],
            ]);
            $c = ConsensusCalculator::improvedCircular($itemsC, [
                'mad_floor'         => $opts['dir_mad_floor'],
                'z_threshold'       => $opts['dir_z_threshold'],
                'use_mad_filtering' => $opts['use_mad_filtering'],
            ]);
        } else {
            $a = ConsensusCalculator::legacyLinear($itemsBase);
            $b = ConsensusCalculator::improvedLinear($itemsBase, [
                'epsilon'             => $opts['epsilon'],
                'mad_floor'           => $opts['mad_floor'],
                'z_threshold'         => $opts['z_threshold'],
                'use_weighted_median' => $opts['use_weighted_median'],
                'use_mad_filtering'   => $opts['use_mad_filtering'],
            ]);
            $c = ConsensusCalculator::improvedLinear($itemsC, [
                'epsilon'             => $opts['epsilon'],
                'mad_floor'           => $opts['mad_floor'],
                'z_threshold'         => $opts['z_threshold'],
                'use_weighted_median' => $opts['use_weighted_median'],
                'use_mad_filtering'   => $opts['use_mad_filtering'],
            ]);
        }

        return ['a' => $a, 'b' => $b, 'c' => $c];
    }

    /**
     * Charge les forecasts archivés de la balise sur la fenêtre, groupés
     * par "target_at|horizon_bucket". La clé est une string composite
     * (PHP ne sait pas indexer par tableau).
     *
     * @return array<string, array<object>>
     */
    private function loadForecasts(int $baliseId, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('forecast_archive_balises')
            ->where('balise_id', $baliseId)
            ->whereBetween('target_at', [$from, $to])
            ->get([
                'weather_model_id',
                'target_at',
                'horizon_bucket',
                'wind_direction',
                'wind_speed_avg',
                'wind_speed_max',
            ]);

        $groups = [];
        foreach ($rows as $row) {
            // target_at peut être string ou DateTime selon le pilote PDO
            $targetAt = $row->target_at instanceof \DateTimeInterface
                ? $row->target_at->format('Y-m-d H:i:s')
                : (string) $row->target_at;

            $key = $targetAt . '|' . $row->horizon_bucket;
            $groups[$key] ??= [];
            $groups[$key][] = $row;
        }

        return $groups;
    }

    /**
     * Charge les observations horaires sur la fenêtre, indexées par hour_at (string Y-m-d H:i:s).
     *
     * @return array<string, BaliseReadingHourly>
     */
    private function loadObservations(int $baliseId, CarbonInterface $from, CarbonInterface $to): array
    {
        return BaliseReadingHourly::query()
            ->where('balise_id', $baliseId)
            ->whereBetween('hour_at', [$from, $to])
            ->get()
            ->keyBy(fn ($r) => $r->hour_at->format('Y-m-d H:i:s'))
            ->all();
    }

    /**
     * Pré-charge en un seul batch tous les weight_factor pertinents :
     * pour chaque (bucket × variable), on identifie les modèles présents
     * et on les fetch d'un coup. Évite N+1.
     *
     * @return array<string, array<int, float>>  Clé "bucket|variable" → [modelId => factor]
     */
    private function preloadWeightFactors(int $baliseId, array $forecastGroups): array
    {
        // Regroupe les modèles présents par bucket
        $modelsByBucket = [];
        foreach ($forecastGroups as $key => $rows) {
            [, $bucket] = explode('|', $key);
            $modelsByBucket[$bucket] ??= [];
            foreach ($rows as $r) {
                $modelsByBucket[$bucket][(int) $r->weather_model_id] = true;
            }
        }

        $out = [];
        foreach ($modelsByBucket as $bucket => $modelMap) {
            $modelIds = array_keys($modelMap);
            foreach (self::VARIABLES as $variable) {
                $out[$bucket . '|' . $variable] = $this->reliability
                    ->weightFactorsBatch($modelIds, $baliseId, $bucket, $variable);
            }
        }
        return $out;
    }

    /**
     * Construit les items pour les consensus A et B (poids = 1.0 partout).
     * Filtre les rows où la variable est null.
     *
     * @return array<array{value: float, weight: float, model_id: int}>
     */
    private function buildItems(array $rows, string $variable): array
    {
        $items = [];
        foreach ($rows as $r) {
            $v = $r->{$variable} ?? null;
            if ($v === null) {
                continue;
            }
            $items[] = [
                'value'    => (float) $v,
                'weight'   => 1.0,
                'model_id' => (int) $r->weather_model_id,
            ];
        }
        return $items;
    }

    /**
     * Recopie une liste d'items en y appliquant les weight_factor de
     * la fiabilité (consensus C). Si un modèle n'a pas de fiabilité
     * connue, garde 1.0.
     */
    private function applyReliabilityWeights(array $items, array $factors, string $bucket, string $variable): array
    {
        $map = $factors[$bucket . '|' . $variable] ?? [];
        return array_map(static function ($it) use ($map) {
            $factor = $map[$it['model_id']] ?? 1.0;
            $it['weight'] = $it['weight'] * $factor;
            return $it;
        }, $items);
    }

    /**
     * Récupère la valeur observée pour une variable depuis un agrégat
     * horaire. Retourne null si pas de reading ou si la variable est
     * absente.
     */
    private function observationFor(?BaliseReadingHourly $obs, string $variable): ?float
    {
        if ($obs === null) {
            return null;
        }
        return match ($variable) {
            'wind_speed_avg' => $obs->wind_speed_avg === null ? null : (float) $obs->wind_speed_avg,
            'wind_speed_max' => $obs->wind_speed_max === null ? null : (float) $obs->wind_speed_max,
            'wind_direction' => $obs->wind_direction === null ? null : (float) $obs->wind_direction,
            default          => null,
        };
    }

    /**
     * Lit les paramètres des consensus depuis les settings, en un seul
     * appel (le service Settings cache déjà tout en Redis).
     *
     * @return array{
     *   epsilon: float,
     *   mad_floor: float,
     *   z_threshold: float,
     *   dir_mad_floor: float,
     *   dir_z_threshold: float,
     *   use_weighted_median: bool,
     *   use_mad_filtering: bool
     * }
     */
    private function resolveOptions(): array
    {
        return [
            'epsilon'             => (float) $this->settings->get('reliability.epsilon_new', 1.0),
            'mad_floor'           => (float) $this->settings->get('reliability.mad_floor', 0.5),
            'z_threshold'         => (float) $this->settings->get('reliability.z_outlier_threshold', 3.0),
            // Pour la direction, on emprunte mad_floor (valeur en degrés
            // raisonnable) et un seuil z dédié.
            'dir_mad_floor'       => (float) $this->settings->get('reliability.mad_floor', 0.5),
            'dir_z_threshold'     => (float) $this->settings->get('reliability.dir_mad_z_threshold', 3.0),
            'use_weighted_median' => (bool)  $this->settings->get('reliability.use_weighted_median', true),
            'use_mad_filtering'   => (bool)  $this->settings->get('reliability.use_mad_filtering', true),
        ];
    }
}
