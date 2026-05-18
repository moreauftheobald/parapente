<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ComputeModelReliabilityJob;
use App\Models\Balise;
use App\Models\ModelReliability;
use App\Models\WeatherModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — Fiabilité par modèle (phase 2.5 / 2).
 *
 * Tableau pivot des MAE / RMSE / bias_signed / weight_factor d'un modèle
 * météo donné, par balise du panel × horizon_bucket × variable.
 *
 * Permet de voir d'un coup d'œil :
 *  - quels modèles sont systématiquement meilleurs / pires sur quel horizon ;
 *  - quels modèles ont un biais signé fort (sur-/sous-estimation chronique) ;
 *  - quels modèles n'ont pas encore atteint le seuil min_samples (cold start).
 *
 * Bouton "Recalculer maintenant" → dispatch sync de
 * `ComputeModelReliabilityJob`, utile après un changement de paramètre
 * (window_days, min_samples) sans attendre 03h30.
 */
class ReliabilityModelsController extends Controller
{
    private const BUCKETS  = ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2'];
    private const VARS     = ['wind_speed_avg', 'wind_speed_max', 'wind_direction'];

    public function index(Request $request): View
    {
        $panel = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $models = WeatherModel::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'active']);

        // Filtres
        $variable = $request->input('variable', 'wind_speed_avg');
        if (! in_array($variable, self::VARS, true)) {
            $variable = 'wind_speed_avg';
        }
        $baliseId = $request->input('balise');
        if ($baliseId !== null && $baliseId !== '') {
            $baliseId = (int) $baliseId;
            if (! $panel->pluck('id')->contains($baliseId)) {
                $baliseId = null;
            }
        } else {
            $baliseId = $panel->first()?->id;
        }

        // Charge toutes les lignes model_reliability pour cette balise × variable
        $rows = ModelReliability::query()
            ->when($baliseId !== null, fn ($q) => $q->where('balise_id', $baliseId))
            ->where('variable', $variable)
            ->get()
            ->groupBy('weather_model_id');

        // Construit le tableau pivot : modèle × bucket
        $pivot = [];
        foreach ($models as $model) {
            $byBucket = $rows->get($model->id, collect());
            $pivot[$model->id] = [
                'model'   => $model,
                'buckets' => [],
                'has_any' => $byBucket->isNotEmpty(),
            ];
            foreach (self::BUCKETS as $bucket) {
                /** @var ModelReliability|null $r */
                $r = $byBucket->firstWhere('horizon_bucket', $bucket);
                $pivot[$model->id]['buckets'][$bucket] = $r;
            }
        }

        // Pour info bandeau
        $lastComputedAt = ModelReliability::query()
            ->when($baliseId !== null, fn ($q) => $q->where('balise_id', $baliseId))
            ->max('computed_at');

        return view('admin.reliability.models', [
            'panel'          => $panel,
            'baliseId'       => $baliseId,
            'variable'       => $variable,
            'buckets'        => self::BUCKETS,
            'pivot'          => $pivot,
            'lastComputedAt' => $lastComputedAt,
        ]);
    }

    /**
     * Recalcule en synchrone la table model_reliability pour toutes les
     * balises du panel. Bloquant (~10 s pour 3 balises), à n'utiliser
     * qu'occasionnellement depuis l'écran admin.
     */
    public function recompute(): RedirectResponse
    {
        ComputeModelReliabilityJob::dispatchSync();

        return redirect()
            ->route('admin.reliability.models')
            ->with('status', 'Fiabilités recalculées sur la fenêtre courante.');
    }
}
