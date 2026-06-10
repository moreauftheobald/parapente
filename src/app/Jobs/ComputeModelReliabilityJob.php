<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Models\Balise;
use App\Services\Weather\Reliability\ReliabilityCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recalcule les métriques de fiabilité (MAE / RMSE / bias / weight_factor)
 * de chaque modèle météo, par balise du panel, par horizon_bucket et par
 * variable.
 *
 * Fenêtre glissante : `reliability.window_days` (défaut 7 j).
 *
 * Schedulé quotidiennement (cf. routes/console.php) — la fenêtre 7 j a
 * beaucoup d'inertie, recalculer plus souvent ne change pas matériellement
 * les classements. Une cadence quotidienne reste suffisamment réactive
 * pour détecter une dérive d'un modèle (mise à jour ratée chez un
 * fournisseur) en ~24 h.
 *
 * Idempotent (upsert sur clé unique
 * `(weather_model_id, balise_id, horizon_bucket, variable)`).
 *
 * Cf. FF_model_reliability.md.
 */
class ComputeModelReliabilityJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $timeout = 600;   // 10 min — large, ~1-2 s par balise en pratique
    public int $tries   = 2;

    protected function monitorGroup(): string
    {
        return 'fiabilite';
    }

    public function handle(ReliabilityCalculator $reliability): void
    {
        $this->trackStart();
        $balises = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->get();

        if ($balises->isEmpty()) {
            Log::info('ComputeModelReliabilityJob: panel vide, rien à calculer.');
            $this->trackSuccess('Panel vide, rien à calculer');
            return;
        }

        $total = 0;
        foreach ($balises as $balise) {
            $start = microtime(true);
            $n     = $reliability->recomputeForBalise($balise);
            $ms    = (microtime(true) - $start) * 1000;
            $total += $n;

            Log::info('ComputeModelReliabilityJob: balise traitée', [
                'balise_id' => $balise->id,
                'name'      => $balise->name,
                'upserted'  => $n,
                'ms'        => round($ms, 1),
            ]);
        }

        Log::info('ComputeModelReliabilityJob: terminé', [
            'balises_count' => $balises->count(),
            'tuples_total'  => $total,
        ]);

        $this->trackSuccess("{$balises->count()} balises, {$total} tuples", ['balises' => $balises->count(), 'tuples' => $total]);
    }

    public function failed(\Throwable $e): void
    {
        $this->trackFailure($e);
    }
}
