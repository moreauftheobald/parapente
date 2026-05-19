<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Services\Weather\Reliability\ReliabilityExportService;
use Illuminate\Http\Request;
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
 *
 * Le calcul est délégué au service `ReliabilityExportService` (méthode
 * `buildHorizonStats`) pour rester DRY avec les exports CSV / JSON.
 */
class ReliabilityHorizonController extends Controller
{
    private const BUCKETS = ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2'];
    private const VARS    = ['wind_speed_avg', 'wind_speed_max', 'wind_direction'];

    public function __construct(private ReliabilityExportService $exportService)
    {
    }

    public function index(Request $request): View
    {
        $panel = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->orderBy('name')
            ->get(['id', 'name', 'source']);

        if ($panel->isEmpty()) {
            return view('admin.reliability.horizon', [
                'panel'         => $panel,
                'balise'        => null,
                'variable'      => 'wind_speed_avg',
                'rowsCommon'    => [],
                'rowsFull'      => [],
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

        $stats = $this->exportService->buildHorizonStats($baliseId, $variable);

        return view('admin.reliability.horizon', [
            'panel'         => $panel,
            'balise'        => $balise,
            'variable'      => $variable,
            'rowsCommon'    => $stats['common'],
            'rowsFull'      => $stats['full'],
            'commonTargets' => $stats['common_targets_count'],
            'fullTargets'   => array_combine(
                self::BUCKETS,
                array_map(fn ($b) => $stats['full'][$b]['target_count'], self::BUCKETS)
            ),
        ]);
    }
}
