<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\BaliseConsensusCompare;
use App\Services\Weather\Reliability\ConsensusCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * BackOffice — MAE par horizon sur target_at communs (phase 2.5).
 *
 * Permet de comparer la **dégradation effective** des consensus en
 * fonction de l'horizon de prévision, sur un ensemble strict de
 * `target_at` communs aux 4 buckets — apples-to-apples.
 *
 * L'écran `/admin/reliability/compare` calcule la MAE de chaque bucket
 * sur **son propre ensemble** d'observations, ce qui peut introduire
 * un biais (les créneaux récents n'ont parfois que `nowcast`, les
 * anciens ont les 4). Ici on isole les `target_at` ayant les 4 buckets
 * présents avec une observation, et on calcule la MAE de chaque bucket
 * sur cet ensemble strict.
 *
 * Bonus : la MAE « full » (= sur l'ensemble complet du bucket) est
 * affichée à côté pour mesurer l'effet du filtre.
 */
class ReliabilityHorizonController extends Controller
{
    private const BUCKETS = ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2'];
    private const VARS    = ['wind_speed_avg', 'wind_speed_max', 'wind_direction'];

    public function index(Request $request): View
    {
        $panel = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->orderBy('name')
            ->get(['id', 'name', 'source']);

        if ($panel->isEmpty()) {
            return view('admin.reliability.horizon', [
                'panel'        => $panel,
                'balise'       => null,
                'variable'     => 'wind_speed_avg',
                'rowsCommon'   => [],
                'rowsFull'     => [],
                'commonTargets' => 0,
                'fullTargets'   => [],
            ]);
        }

        $variable = $request->input('variable', 'wind_speed_avg');
        if (! in_array($variable, self::VARS, true)) {
            $variable = 'wind_speed_avg';
        }

        $baliseId = (int) $request->input('balise', $panel->first()->id);
        if (! $panel->pluck('id')->contains($baliseId)) {
            $baliseId = (int) $panel->first()->id;
        }
        $balise = $panel->firstWhere('id', $baliseId);

        // 1. Charge tous les tuples (balise, variable) avec observation présente
        $rows = BaliseConsensusCompare::query()
            ->where('balise_id', $baliseId)
            ->where('variable', $variable)
            ->whereNotNull('observation')
            ->get(['target_at', 'horizon_bucket', 'consensus_a', 'consensus_b', 'consensus_c', 'observation']);

        // 2. Identifie les target_at communs aux 4 buckets
        $byTarget = $rows->groupBy(fn ($r) => $r->target_at->format('Y-m-d H:i:s'));
        $commonTargets = $byTarget->filter(
            fn (Collection $group) => $group->pluck('horizon_bucket')->unique()->count() === count(self::BUCKETS)
        )->keys()->all();

        // 3. MAE sur l'intersection (target_at présents dans les 4 buckets)
        $rowsCommon = [];
        foreach (self::BUCKETS as $bucket) {
            $subset = $rows->filter(
                fn ($r) => $r->horizon_bucket === $bucket
                          && in_array($r->target_at->format('Y-m-d H:i:s'), $commonTargets, true)
            );
            $rowsCommon[$bucket] = $this->maeOf($subset, $variable);
        }

        // 4. MAE « full » : sur l'ensemble du bucket, sans filtre
        $rowsFull = [];
        foreach (self::BUCKETS as $bucket) {
            $subset = $rows->filter(fn ($r) => $r->horizon_bucket === $bucket);
            $rowsFull[$bucket] = $this->maeOf($subset, $variable);
            $rowsFull[$bucket]['target_count'] = $subset->pluck('target_at')->unique()->count();
        }

        return view('admin.reliability.horizon', [
            'panel'         => $panel,
            'balise'        => $balise,
            'variable'      => $variable,
            'rowsCommon'    => $rowsCommon,
            'rowsFull'      => $rowsFull,
            'commonTargets' => count($commonTargets),
            'fullTargets'   => array_combine(
                self::BUCKETS,
                array_map(fn ($b) => $rowsFull[$b]['target_count'], self::BUCKETS)
            ),
        ]);
    }

    /**
     * @return array{n:int, mae_a:?float, mae_b:?float, mae_c:?float}
     */
    private function maeOf(Collection $rows, string $variable): array
    {
        $n = $rows->count();
        if ($n === 0) {
            return ['n' => 0, 'mae_a' => null, 'mae_b' => null, 'mae_c' => null];
        }

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
            'n'     => $n,
            'mae_a' => $cnt['a'] > 0 ? $sums['a'] / $cnt['a'] : null,
            'mae_b' => $cnt['b'] > 0 ? $sums['b'] / $cnt['b'] : null,
            'mae_c' => $cnt['c'] > 0 ? $sums['c'] / $cnt['c'] : null,
        ];
    }
}
