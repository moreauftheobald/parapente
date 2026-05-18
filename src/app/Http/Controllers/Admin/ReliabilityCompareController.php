<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\BaliseConsensusCompare;
use App\Services\Weather\Reliability\ConsensusCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * BackOffice — Comparaison des consensus (phase 2.5 shadow).
 *
 * Affiche, par balise du panel et par variable, un tableau heure par
 * heure des 3 consensus (A legacy / B amélioré / C amélioré+fiabilité)
 * confrontés à l'observation balise — séparé par horizon de prévision
 * (J / J+1 / J+2).
 *
 * Sert à juger « chiffres en main » si B et C apportent un gain réel par
 * rapport à A sur le terrain réel — pré-requis à l'intégration phase 4
 * (cf. FF_model_reliability.md).
 */
class ReliabilityCompareController extends Controller
{
    /**
     * Buckets exposés. `nowcast` est intégré au regroupement « Aujourd'hui »
     * (J), puisque les 2 buckets concernent des prévisions pour des
     * créneaux du jour courant — la distinction nowcast vs same_day est
     * un détail d'horizon de prévision, pas de cible.
     */
    private const BUCKETS_BY_DAY = [
        'today'    => ['nowcast', 'same_day'],
        'j_plus_1' => ['j_plus_1'],
        'j_plus_2' => ['j_plus_2'],
    ];

    public function index(Request $request): View
    {
        $panel = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->orderBy('name')
            ->get(['id', 'name', 'source']);

        if ($panel->isEmpty()) {
            return view('admin.reliability.compare', [
                'panel'        => $panel,
                'balise'       => null,
                'variable'     => 'wind_speed_avg',
                'sections'     => [],
                'globalStats'  => [],
            ]);
        }

        $variable  = $request->input('variable', 'wind_speed_avg');
        $validVars = ['wind_speed_avg', 'wind_speed_max', 'wind_direction'];
        if (! in_array($variable, $validVars, true)) {
            $variable = 'wind_speed_avg';
        }

        $baliseId = (int) $request->input('balise', $panel->first()->id);
        if (! $panel->pluck('id')->contains($baliseId)) {
            $baliseId = (int) $panel->first()->id;
        }

        $balise = $panel->firstWhere('id', $baliseId);

        // Fenêtre : tous les créneaux ayant une observation OU à venir
        // dans les 72h. On charge généreusement, le tri se fait dans la vue.
        $rows = BaliseConsensusCompare::query()
            ->where('balise_id', $baliseId)
            ->where('variable', $variable)
            ->orderBy('target_at')
            ->get();

        $sections    = $this->groupByDay($rows, $variable);
        $globalStats = $this->globalStats($rows, $variable);

        return view('admin.reliability.compare', [
            'panel'        => $panel,
            'balise'       => $balise,
            'variable'     => $variable,
            'sections'     => $sections,
            'globalStats'  => $globalStats,
        ]);
    }

    /**
     * Découpe les lignes par jour-cible (today / J+1 / J+2) selon le
     * `horizon_bucket`, et calcule pour chaque section :
     *  - la liste des lignes triées par target_at ;
     *  - la MAE de A / B / C sur les lignes ayant une observation.
     *
     * @return array<string, array{label: string, buckets: array<string>, rows: array, mae: array}>
     */
    private function groupByDay(Collection $rows, string $variable): array
    {
        $sections = [];
        foreach (self::BUCKETS_BY_DAY as $key => $buckets) {
            $matching = $rows->whereIn('horizon_bucket', $buckets)->values();
            $sections[$key] = [
                'label'   => match ($key) {
                    'today'    => "Aujourd'hui (nowcast + same_day)",
                    'j_plus_1' => 'Demain (J+1)',
                    'j_plus_2' => 'Après-demain (J+2)',
                },
                'buckets' => $buckets,
                'rows'    => $matching->all(),
                'mae'     => $this->maeOf($matching, $variable),
            ];
        }
        return $sections;
    }

    /**
     * MAE de A / B / C sur l'ensemble du panel (toutes sections
     * confondues), restreinte aux lignes avec observation présente.
     *
     * @return array{n:int, mae_a:?float, mae_b:?float, mae_c:?float}
     */
    private function globalStats(Collection $rows, string $variable): array
    {
        return $this->maeOf($rows, $variable);
    }

    /**
     * Calcule la MAE de chaque consensus sur la collection donnée.
     * Linéaire pour les vitesses, circulaire pour la direction.
     *
     * @return array{n:int, mae_a:?float, mae_b:?float, mae_c:?float}
     */
    private function maeOf(Collection $rows, string $variable): array
    {
        $withObs = $rows->filter(fn ($r) => $r->observation !== null);
        $n       = $withObs->count();
        if ($n === 0) {
            return ['n' => 0, 'mae_a' => null, 'mae_b' => null, 'mae_c' => null];
        }

        $sums = ['a' => 0.0, 'b' => 0.0, 'c' => 0.0];
        $cnt  = ['a' => 0,   'b' => 0,   'c' => 0];

        foreach ($withObs as $r) {
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
            'n'     => $n,
            'mae_a' => $cnt['a'] > 0 ? $sums['a'] / $cnt['a'] : null,
            'mae_b' => $cnt['b'] > 0 ? $sums['b'] / $cnt['b'] : null,
            'mae_c' => $cnt['c'] > 0 ? $sums['c'] / $cnt['c'] : null,
        ];
    }
}
