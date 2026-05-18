<?php

declare(strict_types=1);

namespace App\Services\Weather\Reliability;

use App\Models\Balise;
use App\Models\BaliseConsensusCompare;
use App\Models\ModelReliability;
use App\Models\WeatherModel;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Assemble les données d'export pour l'analyse externe de la phase 2.5 :
 *  - paramètres `reliability.*` courants (snapshot du contexte) ;
 *  - panel de balises actuel ;
 *  - liste des modèles météo actifs ;
 *  - détail complet de `balise_consensus_compare` (consensus A/B/C vs obs) ;
 *  - détail complet de `model_reliability` (MAE/RMSE/biais/weight_factor) ;
 *  - stats MAE agrégées par variable × bucket.
 *
 * Sert au CSV (téléchargé depuis l'écran admin) et au JSON (endpoint
 * `/admin/reliability/export.json`). Le fichier
 * `RELIABILITY_ANALYSIS_CONTEXT.md` documente comment lire la structure.
 */
class ReliabilityExportService
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * Construit le payload JSON complet. Aucune limite de fenêtre — on
     * dump tout ce qui est en base (rétention 14 j pour
     * `balise_consensus_compare`, 7 j glissants pour `model_reliability`).
     *
     * @return array<string, mixed>
     */
    public function buildJsonPayload(): array
    {
        $now = CarbonImmutable::now();

        $panel = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->orderBy('name')
            ->get(['id', 'name', 'source', 'reliability_class', 'latitude', 'longitude', 'altitude_m'])
            ->map(fn ($b) => [
                'id'                => $b->id,
                'name'              => $b->name,
                'source'            => $b->source,
                'reliability_class' => $b->reliability_class,
                'latitude'          => (float) $b->latitude,
                'longitude'         => (float) $b->longitude,
                'altitude_m'        => $b->altitude_m,
            ])
            ->all();

        $models = WeatherModel::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'provider'])
            ->map(fn ($m) => [
                'id'       => $m->id,
                'code'     => $m->code,
                'name'     => $m->name,
                'provider' => $m->provider,
            ])
            ->all();

        $compareRows = BaliseConsensusCompare::query()
            ->orderBy('balise_id')
            ->orderBy('target_at')
            ->orderBy('horizon_bucket')
            ->orderBy('variable')
            ->get()
            ->map(fn ($r) => [
                'balise_id'         => (int) $r->balise_id,
                'target_at'         => $r->target_at->format('Y-m-d H:i:s'),
                'horizon_bucket'    => $r->horizon_bucket,
                'variable'          => $r->variable,
                'consensus_a'       => $r->consensus_a !== null ? (float) $r->consensus_a : null,
                'consensus_b'       => $r->consensus_b !== null ? (float) $r->consensus_b : null,
                'consensus_c'       => $r->consensus_c !== null ? (float) $r->consensus_c : null,
                'observation'       => $r->observation !== null ? (float) $r->observation : null,
                'observation_count' => $r->observation_count,
                'models_count'      => (int) $r->models_count,
                'mad_value'         => $r->mad_value !== null ? (float) $r->mad_value : null,
                'computed_at'       => $r->computed_at?->format('Y-m-d H:i:s'),
            ])
            ->all();

        $reliabilityRows = ModelReliability::query()
            ->orderBy('balise_id')
            ->orderBy('weather_model_id')
            ->orderBy('horizon_bucket')
            ->orderBy('variable')
            ->get()
            ->map(fn ($r) => [
                'weather_model_id' => (int) $r->weather_model_id,
                'balise_id'        => (int) $r->balise_id,
                'horizon_bucket'   => $r->horizon_bucket,
                'variable'         => $r->variable,
                'mae'              => $r->mae !== null ? (float) $r->mae : null,
                'rmse'             => $r->rmse !== null ? (float) $r->rmse : null,
                'bias_signed'      => $r->bias_signed !== null ? (float) $r->bias_signed : null,
                'weight_factor'    => $r->weight_factor !== null ? (float) $r->weight_factor : null,
                'samples_n'        => (int) $r->samples_n,
                'computed_at'      => $r->computed_at?->format('Y-m-d H:i:s'),
            ])
            ->all();

        return [
            'exported_at'       => $now->format('Y-m-d\TH:i:sP'),
            'schema_version'    => 1,
            'parameters'        => $this->collectParameters(),
            'panel'             => $panel,
            'models'            => $models,
            'stats'             => $this->buildAggregateStats(),
            'consensus_compare' => $compareRows,
            'model_reliability' => $reliabilityRows,
        ];
    }

    /**
     * Capture tous les paramètres `reliability.*` courants ainsi que
     * quelques métadonnées utiles à l'analyse (timezone, version Laravel,
     * date du dernier calcul).
     *
     * @return array<string, mixed>
     */
    private function collectParameters(): array
    {
        $all = $this->settings->all();
        $reliability = [];
        foreach ($all as $key => $value) {
            if (str_starts_with($key, 'reliability.')) {
                $reliability[$key] = $value;
            }
        }

        $lastReliabilityComputedAt = ModelReliability::query()
            ->max('computed_at');
        $lastCompareComputedAt = BaliseConsensusCompare::query()
            ->max('computed_at');

        return [
            'reliability_settings'         => $reliability,
            'app_timezone'                 => config('app.timezone'),
            'last_reliability_computed_at' => $lastReliabilityComputedAt,
            'last_compare_computed_at'     => $lastCompareComputedAt,
        ];
    }

    /**
     * Stats agrégées par variable × bucket sur les tuples avec observation.
     * MAE linéaire pour les vitesses, circulaire pour la direction.
     *
     * @return array<string, array<string, array{n:int, mae_a:?float, mae_b:?float, mae_c:?float}>>
     */
    private function buildAggregateStats(): array
    {
        $out = [];
        $rows = BaliseConsensusCompare::query()
            ->whereNotNull('observation')
            ->get();

        $byKey = $rows->groupBy(fn ($r) => $r->variable . '|' . $r->horizon_bucket);

        foreach ($byKey as $key => $group) {
            [$variable, $bucket] = explode('|', $key);
            $out[$variable][$bucket] = $this->maeOfGroup($group, $variable);
        }
        return $out;
    }

    /**
     * @return array{n:int, mae_a:?float, mae_b:?float, mae_c:?float}
     */
    private function maeOfGroup(Collection $rows, string $variable): array
    {
        $sums = ['a' => 0.0, 'b' => 0.0, 'c' => 0.0];
        $cnt  = ['a' => 0,   'b' => 0,   'c' => 0];

        foreach ($rows as $r) {
            $obs = (float) $r->observation;
            foreach (['a', 'b', 'c'] as $k) {
                $val = $r->{"consensus_$k"};
                if ($val === null) {
                    continue;
                }
                $err = $variable === 'wind_direction'
                    ? ConsensusCalculator::circularDistance((float) $val, $obs)
                    : abs((float) $val - $obs);
                $sums[$k] += $err;
                $cnt[$k]++;
            }
        }

        return [
            'n'     => $rows->count(),
            'mae_a' => $cnt['a'] > 0 ? round($sums['a'] / $cnt['a'], 3) : null,
            'mae_b' => $cnt['b'] > 0 ? round($sums['b'] / $cnt['b'], 3) : null,
            'mae_c' => $cnt['c'] > 0 ? round($sums['c'] / $cnt['c'], 3) : null,
        ];
    }

    /**
     * Construit les lignes CSV pour `balise_consensus_compare`, avec
     * filtres optionnels par balise et variable. Le nom de la balise
     * est résolu pour faciliter la lecture dans Excel.
     *
     * Itérateur générateur — pas de chargement complet en mémoire.
     *
     * @return iterable<array<string, scalar|null>>
     */
    public function consensusCompareCsvRows(?int $baliseId = null, ?string $variable = null): iterable
    {
        $baliseNameById = Balise::query()
            ->whereIn('id', BaliseConsensusCompare::query()->distinct()->pluck('balise_id'))
            ->pluck('name', 'id')
            ->all();

        $query = BaliseConsensusCompare::query()
            ->when($baliseId !== null, fn ($q) => $q->where('balise_id', $baliseId))
            ->when($variable !== null, fn ($q) => $q->where('variable', $variable))
            ->orderBy('balise_id')
            ->orderBy('target_at')
            ->orderBy('horizon_bucket')
            ->orderBy('variable');

        foreach ($query->cursor() as $r) {
            $err = static function ($val, $obs, $isDir) {
                if ($val === null || $obs === null) return null;
                return $isDir
                    ? ConsensusCalculator::circularDistance((float) $val, (float) $obs)
                    : abs((float) $val - (float) $obs);
            };
            $isDir = $r->variable === 'wind_direction';

            yield [
                'balise_id'         => (int) $r->balise_id,
                'balise_name'       => $baliseNameById[$r->balise_id] ?? '',
                'target_at'         => $r->target_at->format('Y-m-d H:i:s'),
                'horizon_bucket'    => $r->horizon_bucket,
                'variable'          => $r->variable,
                'consensus_a'       => $r->consensus_a,
                'consensus_b'       => $r->consensus_b,
                'consensus_c'       => $r->consensus_c,
                'observation'       => $r->observation,
                'err_a'             => $err($r->consensus_a, $r->observation, $isDir) !== null
                                        ? round($err($r->consensus_a, $r->observation, $isDir), 3) : null,
                'err_b'             => $err($r->consensus_b, $r->observation, $isDir) !== null
                                        ? round($err($r->consensus_b, $r->observation, $isDir), 3) : null,
                'err_c'             => $err($r->consensus_c, $r->observation, $isDir) !== null
                                        ? round($err($r->consensus_c, $r->observation, $isDir), 3) : null,
                'observation_count' => $r->observation_count,
                'models_count'      => (int) $r->models_count,
                'mad_value'         => $r->mad_value,
                'computed_at'       => $r->computed_at?->format('Y-m-d H:i:s'),
            ];
        }
    }

    /**
     * Idem pour `model_reliability` — joint avec le nom du modèle et de
     * la balise pour faciliter la lecture.
     *
     * @return iterable<array<string, scalar|null>>
     */
    public function modelReliabilityCsvRows(?int $baliseId = null, ?string $variable = null): iterable
    {
        $modelById  = WeatherModel::query()->pluck('name', 'id')->all();
        $modelCode  = WeatherModel::query()->pluck('code', 'id')->all();
        $baliseById = Balise::query()->pluck('name', 'id')->all();

        $query = ModelReliability::query()
            ->when($baliseId !== null, fn ($q) => $q->where('balise_id', $baliseId))
            ->when($variable !== null, fn ($q) => $q->where('variable', $variable))
            ->orderBy('balise_id')
            ->orderBy('weather_model_id')
            ->orderBy('horizon_bucket')
            ->orderBy('variable');

        foreach ($query->cursor() as $r) {
            yield [
                'weather_model_id'   => (int) $r->weather_model_id,
                'weather_model_code' => $modelCode[$r->weather_model_id] ?? '',
                'weather_model_name' => $modelById[$r->weather_model_id]  ?? '',
                'balise_id'          => (int) $r->balise_id,
                'balise_name'        => $baliseById[$r->balise_id] ?? '',
                'horizon_bucket'     => $r->horizon_bucket,
                'variable'           => $r->variable,
                'mae'                => $r->mae,
                'rmse'               => $r->rmse,
                'bias_signed'        => $r->bias_signed,
                'weight_factor'      => $r->weight_factor,
                'samples_n'          => (int) $r->samples_n,
                'computed_at'        => $r->computed_at?->format('Y-m-d H:i:s'),
            ];
        }
    }
}
