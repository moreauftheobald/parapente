<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\TracksExecution;
use App\Models\Balise;
use App\Services\Weather\Reliability\BaliseConsensusCompareService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recalcule les 3 consensus (A legacy / B amélioré / C amélioré+fiabilité)
 * pour les balises du panel sur la fenêtre [now-72h, now+72h] et les
 * persiste dans `balise_consensus_compare`.
 *
 * Schedulé toutes les heures à :10 (cf. routes/console.php) — après :
 *  - :00 FetchBaliseForecastsJob (archive les prévisions)
 *  - :05 AggregateBaliseReadingsHourlyJob (agrège les readings)
 *
 * À :10 on a donc à la fois la dernière prévision disponible et la
 * dernière observation agrégée. La fenêtre ±72h couvre les 3 horizons
 * comparables (J / J+1 / J+2) — au-delà, les buckets sont absents de
 * `forecast_archive_balises` (rétention 7j).
 *
 * Honore le kill switch global `reliability.shadow_enabled` — le service
 * sous-jacent retourne 0 si désactivé.
 *
 * Idempotent (upsert sur clé unique
 * `(balise_id, target_at, horizon_bucket, variable)`).
 *
 * Cf. FF_model_reliability.md.
 */
class ComputeBaliseConsensusCompareJob implements ShouldQueue
{
    use Queueable;
    use TracksExecution;

    public int $timeout = 300;   // 5 min — ~6 s par balise en pratique
    public int $tries   = 2;

    /** Demi-fenêtre en heures (passé + futur). 72 = J / J+1 / J+2. */
    private const WINDOW_HOURS = 72;

    protected function monitorGroup(): string
    {
        return 'fiabilite';
    }

    public function handle(BaliseConsensusCompareService $service): void
    {
        $this->trackStart();
        $balises = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->get();

        if ($balises->isEmpty()) {
            Log::info('ComputeBaliseConsensusCompareJob: panel vide, rien à calculer.');
            $this->trackSuccess('Panel vide, rien à calculer');
            return;
        }

        $from = Carbon::now()->subHours(self::WINDOW_HOURS)->startOfHour();
        $to   = Carbon::now()->addHours(self::WINDOW_HOURS)->startOfHour();

        $total = 0;
        foreach ($balises as $balise) {
            $start = microtime(true);
            $n     = $service->computeForBalise($balise, $from, $to);
            $ms    = (microtime(true) - $start) * 1000;
            $total += $n;

            Log::info('ComputeBaliseConsensusCompareJob: balise traitée', [
                'balise_id' => $balise->id,
                'name'      => $balise->name,
                'upserted'  => $n,
                'ms'        => round($ms, 1),
            ]);
        }

        Log::info('ComputeBaliseConsensusCompareJob: terminé', [
            'window_start'  => $from->toDateTimeString(),
            'window_end'    => $to->toDateTimeString(),
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
