<?php

declare(strict_types=1);

namespace App\Services\Weather\Reliability;

use App\Models\Balise;
use App\Models\ModelReliability;
use App\Services\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lecture du `weight_factor` d'un modèle météo pour une balise et un
 * horizon donnés, avec clamp et garde-fou cold start.
 *
 * Le calcul effectif de MAE/RMSE/bias et la mise à jour du
 * `weight_factor` sont à la charge d'un job dédié
 * `ComputeModelReliabilityJob` (commit ultérieur, phase 2). Tant que
 * ce job n'a pas tourné, la table `model_reliability` est vide et ce
 * service retourne 1.0 pour tous les couples — ce qui rend le
 * consensus C identique au consensus B (cold start).
 *
 * Cf. FF_model_reliability.md.
 */
class ReliabilityCalculator
{
    /**
     * Cache mémoire (par process) pour éviter N requêtes SQL dans une
     * même boucle de scoring. Clé : "{modelId}:{baliseId}:{bucket}:{variable}".
     *
     * @var array<string, float>
     */
    private array $cache = [];

    public function __construct(private Settings $settings)
    {
    }

    /**
     * Retourne le multiplicateur de fiabilité à appliquer aux poids d'un
     * modèle pour un (balise, bucket, variable) donné.
     *
     * Retourne 1.0 (neutre) si :
     *  - aucune ligne `model_reliability` n'existe pour ce tuple ;
     *  - `samples_n` est inférieur au seuil `reliability.min_samples`.
     *
     * Sinon, retourne la valeur de `weight_factor` clampée entre
     * `reliability.factor_min` et `reliability.factor_max` (sécurité —
     * la valeur en base devrait déjà être clampée à l'écriture).
     */
    public function weightFactorFor(
        int $weatherModelId,
        int $baliseId,
        string $horizonBucket,
        string $variable
    ): float {
        $key = "{$weatherModelId}:{$baliseId}:{$horizonBucket}:{$variable}";
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $row = ModelReliability::query()
            ->where('weather_model_id', $weatherModelId)
            ->where('balise_id', $baliseId)
            ->where('horizon_bucket', $horizonBucket)
            ->where('variable', $variable)
            ->first(['weight_factor', 'samples_n']);

        $value = $this->resolveValue($row?->samples_n, $row?->weight_factor);
        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Variante batch : charge en une requête toutes les fiabilités
     * pertinentes pour (balise × bucket × variable × N modèles).
     * Utilisée par le service de comparaison consensus qui itère sur
     * tous les modèles d'un créneau.
     *
     * @param array<int> $modelIds
     * @return array<int, float>  modelId => weight_factor
     */
    public function weightFactorsBatch(
        array $modelIds,
        int $baliseId,
        string $horizonBucket,
        string $variable
    ): array {
        if ($modelIds === []) {
            return [];
        }

        $rows = ModelReliability::query()
            ->whereIn('weather_model_id', $modelIds)
            ->where('balise_id', $baliseId)
            ->where('horizon_bucket', $horizonBucket)
            ->where('variable', $variable)
            ->get(['weather_model_id', 'weight_factor', 'samples_n']);

        $byModel = [];
        foreach ($rows as $r) {
            $byModel[(int) $r->weather_model_id] = $this->resolveValue(
                (int) $r->samples_n,
                (float) $r->weight_factor
            );
        }

        // Modèles sans ligne en base → 1.0 (cold start).
        $out = [];
        foreach ($modelIds as $id) {
            $out[(int) $id] = $byModel[(int) $id] ?? 1.0;
        }
        return $out;
    }

    private function resolveValue(?int $samplesN, mixed $weightFactor): float
    {
        if ($samplesN === null || $weightFactor === null) {
            return 1.0;
        }
        $minSamples = (int) $this->settings->get('reliability.min_samples', 50);
        if ($samplesN < $minSamples) {
            return 1.0;
        }
        $min = (float) $this->settings->get('reliability.factor_min', 0.25);
        $max = (float) $this->settings->get('reliability.factor_max', 2.0);
        return max($min, min($max, (float) $weightFactor));
    }

    // ── Recalcul effectif des métriques de fiabilité ──────────────

    /**
     * Recalcule les MAE / RMSE / bias_signed / weight_factor pour tous
     * les couples (modèle × bucket × variable) d'une balise sur la
     * fenêtre glissante (`reliability.window_days`, défaut 7 j).
     *
     * Algorithme — pour chaque horizon_bucket :
     *  1. Joint `forecast_archive_balises` × `balise_readings_hourly`
     *     sur (balise_id, target_at = hour_at) ;
     *  2. Pour chaque modèle et chaque variable, agrège les paires
     *     (prévu, observé) en MAE / RMSE / bias ;
     *  3. Calcule la médiane des MAE des modèles éligibles
     *     (`samples_n >= min_samples`) et dérive le `weight_factor`
     *     normalisé, clampé entre `factor_min` et `factor_max` ;
     *  4. Upsert dans `model_reliability`.
     *
     * Idempotent. Retourne le nombre de tuples upsertés.
     */
    public function recomputeForBalise(Balise $balise, ?int $windowDays = null): int
    {
        $windowDays ??= (int) $this->settings->get('reliability.window_days', 7);
        $minSamples  = (int)   $this->settings->get('reliability.min_samples', 50);
        $factorMin   = (float) $this->settings->get('reliability.factor_min', 0.25);
        $factorMax   = (float) $this->settings->get('reliability.factor_max', 2.0);

        $from = Carbon::now()->subDays($windowDays);
        $now  = Carbon::now();

        $buckets  = ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2'];
        $upserted = 0;

        foreach ($buckets as $bucket) {
            $pairs = $this->loadPairs($balise->id, $bucket, $from);
            if ($pairs === []) {
                continue;
            }

            // Agrégation : par modèle × variable, accumule les erreurs
            // et biais individuels (linéaire ou circulaire selon la
            // variable).
            $stats = $this->aggregateStats($pairs);

            // Pour chaque variable, calcule la médiane des MAE des
            // modèles éligibles, puis dérive le weight_factor de chaque
            // modèle et upsert dans model_reliability.
            foreach ($stats as $variable => $modelStats) {
                $medianMae = $this->medianOfEligibleMaes($modelStats, $minSamples);

                foreach ($modelStats as $modelId => $s) {
                    $factor = $this->computeFactor(
                        $s['mae'], $s['n'], $medianMae,
                        $minSamples, $factorMin, $factorMax
                    );

                    ModelReliability::query()->updateOrCreate(
                        [
                            'weather_model_id' => $modelId,
                            'balise_id'        => $balise->id,
                            'horizon_bucket'   => $bucket,
                            'variable'         => $variable,
                        ],
                        [
                            'mae'           => round($s['mae'], 2),
                            'rmse'          => round($s['rmse'], 2),
                            'bias_signed'   => round($s['bias'], 2),
                            'weight_factor' => round($factor, 2),
                            'samples_n'     => $s['n'],
                            'computed_at'   => $now,
                        ]
                    );
                    $upserted++;
                }
            }
        }

        // Invalide le cache mémoire interne (sécurité — même process).
        $this->cache = [];

        return $upserted;
    }

    /**
     * Charge les paires (forecast, observation) pour un bucket donné.
     * Joint forecast_archive_balises et balise_readings_hourly sur
     * (balise_id, target_at = hour_at).
     *
     * @return array<object>
     */
    private function loadPairs(int $baliseId, string $bucket, CarbonInterface $from): array
    {
        return DB::table('forecast_archive_balises as fab')
            ->join('balise_readings_hourly as brh', function ($join) {
                $join->on('brh.balise_id', '=', 'fab.balise_id')
                     ->on('brh.hour_at',  '=', 'fab.target_at');
            })
            ->where('fab.balise_id', $baliseId)
            ->where('fab.horizon_bucket', $bucket)
            ->where('fab.target_at', '>=', $from)
            ->select(
                'fab.weather_model_id',
                'fab.wind_direction as pred_dir',
                'fab.wind_speed_avg as pred_avg',
                'fab.wind_speed_max as pred_max',
                'brh.wind_direction as obs_dir',
                'brh.wind_speed_avg as obs_avg',
                'brh.wind_speed_max as obs_max',
            )
            ->get()
            ->all();
    }

    /**
     * Pour un lot de paires (forecast, observation), produit pour chaque
     * (variable × modèle) la MAE, RMSE, bias et n_samples.
     *
     * - vitesses : linéaire (différence absolue / signée).
     * - direction : circulaire (ConsensusCalculator::circularDistance et
     *   signedCircularDiff).
     *
     * @return array<string, array<int, array{mae: float, rmse: float, bias: float, n: int}>>
     *         clé externe = variable, clé interne = modelId.
     */
    private function aggregateStats(array $pairs): array
    {
        // Collecte par (variable, modelId) : listes d'erreurs et biais
        $bucket = [
            'wind_speed_avg' => [],
            'wind_speed_max' => [],
            'wind_direction' => [],
        ];

        foreach ($pairs as $p) {
            $mid = (int) $p->weather_model_id;

            // Vitesse moyenne (linéaire)
            if ($p->pred_avg !== null && $p->obs_avg !== null) {
                $bucket['wind_speed_avg'][$mid] ??= ['errs' => [], 'biases' => []];
                $diff = (float) $p->pred_avg - (float) $p->obs_avg;
                $bucket['wind_speed_avg'][$mid]['errs'][]   = abs($diff);
                $bucket['wind_speed_avg'][$mid]['biases'][] = $diff;
            }

            // Rafale (linéaire)
            if ($p->pred_max !== null && $p->obs_max !== null) {
                $bucket['wind_speed_max'][$mid] ??= ['errs' => [], 'biases' => []];
                $diff = (float) $p->pred_max - (float) $p->obs_max;
                $bucket['wind_speed_max'][$mid]['errs'][]   = abs($diff);
                $bucket['wind_speed_max'][$mid]['biases'][] = $diff;
            }

            // Direction (circulaire)
            if ($p->pred_dir !== null && $p->obs_dir !== null) {
                $bucket['wind_direction'][$mid] ??= ['errs' => [], 'biases' => []];
                $bucket['wind_direction'][$mid]['errs'][] =
                    ConsensusCalculator::circularDistance((float) $p->pred_dir, (float) $p->obs_dir);
                $bucket['wind_direction'][$mid]['biases'][] =
                    ConsensusCalculator::signedCircularDiff((float) $p->pred_dir, (float) $p->obs_dir);
            }
        }

        // Agrège chaque liste en MAE / RMSE / bias / n
        $out = [];
        foreach ($bucket as $variable => $byModel) {
            foreach ($byModel as $mid => $data) {
                $n   = count($data['errs']);
                if ($n === 0) {
                    continue;
                }
                $mae  = array_sum($data['errs']) / $n;
                $rmse = sqrt(array_sum(array_map(static fn ($e) => $e * $e, $data['errs'])) / $n);
                $bias = array_sum($data['biases']) / $n;
                $out[$variable][$mid] = [
                    'mae'  => $mae,
                    'rmse' => $rmse,
                    'bias' => $bias,
                    'n'    => $n,
                ];
            }
        }
        return $out;
    }

    /**
     * Médiane des MAE parmi les modèles ayant `samples_n >= minSamples`.
     * Retourne null si aucun modèle n'est éligible (cold start).
     */
    private function medianOfEligibleMaes(array $modelStats, int $minSamples): ?float
    {
        $maes = [];
        foreach ($modelStats as $s) {
            if ($s['n'] >= $minSamples) {
                $maes[] = $s['mae'];
            }
        }
        if ($maes === []) {
            return null;
        }
        sort($maes);
        return $maes[(int) floor(count($maes) / 2)];
    }

    /**
     * Dérive le weight_factor d'un modèle :
     *  - 1.0 si trop peu d'échantillons ou pas de médiane comparative ;
     *  - sinon `medianMae / mae`, clampé dans [factorMin, factorMax].
     *
     * Garde-fou MAE = 0 : impossible en pratique sur des dizaines de
     * paires (sauf bug), mais on retourne factorMax pour éviter la
     * division par zéro.
     */
    private function computeFactor(
        float $mae,
        int $n,
        ?float $medianMae,
        int $minSamples,
        float $factorMin,
        float $factorMax
    ): float {
        if ($medianMae === null || $n < $minSamples) {
            return 1.0;
        }
        if ($mae <= 0.0) {
            return $factorMax;
        }
        $ratio = $medianMae / $mae;
        return max($factorMin, min($factorMax, $ratio));
    }
}
