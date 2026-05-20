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
     * Liste les `id` des balises actuellement dans le panel actif. Sert
     * à filtrer les exports/stats pour ne pas inclure les reliquats de
     * balises sorties (les jobs upsertent mais n'effacent jamais).
     *
     * @return array<int>
     */
    private function currentPanelIds(): array
    {
        return Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->pluck('id')
            ->all();
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

        $panelBalises = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->orderBy('name')
            ->get(['id', 'name', 'source', 'reliability_class', 'latitude', 'longitude', 'altitude_m']);

        // Snapshot des balise_id strictement présentes dans le panel
        // au moment de l'export. Toutes les sections de données qui
        // suivent (consensus_compare, model_reliability, stats) sont
        // filtrées sur cet ensemble pour éviter de polluer l'export
        // avec des reliquats de balises sorties du panel (les jobs
        // upsertent mais n'effacent jamais).
        $panelIds = $panelBalises->pluck('id')->all();

        $panel = $panelBalises
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
            ->whereIn('balise_id', $panelIds)
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
            ->whereIn('balise_id', $panelIds)
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
            'schema_version'    => 2,
            'parameters'        => $this->collectParameters(),
            'panel'             => $panel,
            'models'            => $models,
            'stats'             => $this->buildAggregateStats($panelIds),
            'horizon_mae'       => $this->buildHorizonMaeAllBalises($panel),
            'consensus_compare' => $compareRows,
            'model_reliability' => $reliabilityRows,
        ];
    }

    /**
     * Calcule la MAE par horizon (common + full) pour toutes les balises
     * du panel × toutes les variables.
     *
     * Retourne une structure indexée par balise_id puis variable :
     *   [12 => ['wind_speed_avg' => {common_targets_count, common: {bucket→{...}}, full: {bucket→{...}}}, ...]]
     *
     * @param array<array<string, mixed>> $panel
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function buildHorizonMaeAllBalises(array $panel): array
    {
        $variables = ['wind_speed_avg', 'wind_speed_max', 'wind_direction'];
        $out       = [];
        foreach ($panel as $b) {
            $byVariable = [];
            foreach ($variables as $variable) {
                $stats = $this->buildHorizonStats((int) $b['id'], $variable);
                // Arrondi pour des clés JSON propres et plus légères
                foreach (['common', 'full'] as $set) {
                    foreach ($stats[$set] as $bucket => $row) {
                        foreach (['mae_a', 'mae_b', 'mae_c'] as $k) {
                            if (isset($row[$k]) && $row[$k] !== null) {
                                $stats[$set][$bucket][$k] = round((float) $row[$k], 3);
                            }
                        }
                    }
                }
                $byVariable[$variable] = $stats;
            }
            $out[(int) $b['id']] = $byVariable;
        }
        return $out;
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
     * Stats agrégées par variable × bucket sur les tuples avec observation,
     * **restreintes au panel passé en argument** (les jobs n'effacent
     * jamais les vieux tuples, sans ce filtre on agrège aussi les balises
     * sorties du panel).
     *
     * MAE linéaire pour les vitesses, circulaire pour la direction.
     *
     * @param array<int> $panelIds
     * @return array<string, array<string, array{n:int, mae_a:?float, mae_b:?float, mae_c:?float}>>
     */
    private function buildAggregateStats(array $panelIds): array
    {
        $out = [];
        if ($panelIds === []) {
            return $out;
        }
        $rows = BaliseConsensusCompare::query()
            ->whereIn('balise_id', $panelIds)
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

        // Sans filtre balise explicite : restreindre au panel courant
        // pour ne pas exporter les reliquats de balises sorties.
        $panelIds = $baliseId === null ? $this->currentPanelIds() : null;

        $query = BaliseConsensusCompare::query()
            ->when($baliseId !== null, fn ($q) => $q->where('balise_id', $baliseId))
            ->when($panelIds !== null, fn ($q) => $q->whereIn('balise_id', $panelIds))
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

        $panelIds = $baliseId === null ? $this->currentPanelIds() : null;

        $query = ModelReliability::query()
            ->when($baliseId !== null, fn ($q) => $q->where('balise_id', $baliseId))
            ->when($panelIds !== null, fn ($q) => $q->whereIn('balise_id', $panelIds))
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

    // ── MAE par horizon sur target_at communs ─────────────────────

    private const BUCKETS = ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2'];

    /**
     * Calcule pour une balise × variable donnée :
     *  - la MAE de chaque bucket sur les target_at présents dans les 4
     *    buckets simultanément avec observation ("common" / strict) ;
     *  - la MAE de chaque bucket sur l'ensemble complet ("full" / par
     *    bucket pris isolément).
     *
     * Source unique de vérité pour l'écran admin et l'export — évite
     * la duplication de la logique.
     *
     * @return array{
     *   common_targets_count: int,
     *   common: array<string, array{n:int, mae_a:?float, mae_b:?float, mae_c:?float}>,
     *   full:   array<string, array{n:int, mae_a:?float, mae_b:?float, mae_c:?float, target_count:int}>
     * }
     */
    public function buildHorizonStats(int $baliseId, string $variable): array
    {
        $rows = BaliseConsensusCompare::query()
            ->where('balise_id', $baliseId)
            ->where('variable', $variable)
            ->whereNotNull('observation')
            ->get(['target_at', 'horizon_bucket', 'consensus_a', 'consensus_b', 'consensus_c', 'observation']);

        // Target_at présents dans les 4 buckets simultanément
        $byTarget = $rows->groupBy(fn ($r) => $r->target_at->format('Y-m-d H:i:s'));
        $commonTargets = $byTarget->filter(
            fn (Collection $group) => $group->pluck('horizon_bucket')->unique()->count() === count(self::BUCKETS)
        )->keys()->all();
        $commonSet = array_flip($commonTargets);

        $common = [];
        $full   = [];
        foreach (self::BUCKETS as $bucket) {
            $bucketRows = $rows->filter(fn ($r) => $r->horizon_bucket === $bucket);

            // Common (strict)
            $strictRows = $bucketRows->filter(
                fn ($r) => isset($commonSet[$r->target_at->format('Y-m-d H:i:s')])
            );
            $common[$bucket] = $this->maeOfGroup($strictRows->values(), $variable);

            // Full (par bucket pris isolément)
            $fullStats = $this->maeOfGroup($bucketRows->values(), $variable);
            $fullStats['target_count'] = $bucketRows->pluck('target_at')->unique()->count();
            $full[$bucket] = $fullStats;
        }

        return [
            'common_targets_count' => count($commonTargets),
            'common'               => $common,
            'full'                 => $full,
        ];
    }

    /**
     * Aplatit les stats d'horizon en lignes CSV (1 ligne par balise ×
     * variable × bucket × set, où `set` ∈ {common, full}).
     *
     * Filtres optionnels (sinon : toutes les balises du panel × toutes
     * les variables).
     *
     * @return iterable<array<string, scalar|null>>
     */
    public function horizonStatsCsvRows(?int $baliseId = null, ?string $variable = null): iterable
    {
        $balises = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->when($baliseId !== null, fn ($q) => $q->where('id', $baliseId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $variables = $variable !== null
            ? [$variable]
            : ['wind_speed_avg', 'wind_speed_max', 'wind_direction'];

        foreach ($balises as $b) {
            foreach ($variables as $v) {
                $stats = $this->buildHorizonStats((int) $b->id, $v);

                foreach (self::BUCKETS as $bucket) {
                    $row = $stats['common'][$bucket];
                    yield [
                        'balise_id'             => (int) $b->id,
                        'balise_name'           => $b->name,
                        'variable'              => $v,
                        'set'                   => 'common',
                        'horizon_bucket'        => $bucket,
                        'common_targets_count'  => $stats['common_targets_count'],
                        'bucket_target_count'   => null,
                        'n'                     => $row['n'],
                        'mae_a'                 => $row['mae_a'] !== null ? round((float) $row['mae_a'], 3) : null,
                        'mae_b'                 => $row['mae_b'] !== null ? round((float) $row['mae_b'], 3) : null,
                        'mae_c'                 => $row['mae_c'] !== null ? round((float) $row['mae_c'], 3) : null,
                    ];

                    $rowFull = $stats['full'][$bucket];
                    yield [
                        'balise_id'             => (int) $b->id,
                        'balise_name'           => $b->name,
                        'variable'              => $v,
                        'set'                   => 'full',
                        'horizon_bucket'        => $bucket,
                        'common_targets_count'  => null,
                        'bucket_target_count'   => $rowFull['target_count'],
                        'n'                     => $rowFull['n'],
                        'mae_a'                 => $rowFull['mae_a'] !== null ? round((float) $rowFull['mae_a'], 3) : null,
                        'mae_b'                 => $rowFull['mae_b'] !== null ? round((float) $rowFull['mae_b'], 3) : null,
                        'mae_c'                 => $rowFull['mae_c'] !== null ? round((float) $rowFull['mae_c'], 3) : null,
                    ];
                }
            }
        }
    }
}
