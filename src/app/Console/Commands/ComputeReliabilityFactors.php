<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Services\Weather\Reliability\ReliabilityCalculator;
use Illuminate\Console\Command;

/**
 * Recalcule MAE / RMSE / bias / weight_factor pour tous les couples
 * (modèle × bucket × variable) des balises du panel (phase 2.5).
 *
 * Utilitaire de mise au point — équivalent du job
 * `ComputeModelReliabilityJob` lancé en synchrone.
 *
 *   php artisan reliability:compute-factors             # toutes les balises du panel
 *   php artisan reliability:compute-factors --balise=12
 *   php artisan reliability:compute-factors --days=14   # fenêtre étendue
 *
 * Idempotent (upsert sur clé unique). Lit / écrit dans `model_reliability`.
 *
 * Note : tant que la fenêtre cumulée d'historique
 * (`forecast_archive_balises` × `balise_readings_hourly`) est < ~7 jours,
 * `samples_n` reste sous le seuil `reliability.min_samples` (50 par
 * défaut) et tous les `weight_factor` valent 1.0. Cf. FF — *cold start*.
 */
class ComputeReliabilityFactors extends Command
{
    protected $signature = 'reliability:compute-factors
        {--balise= : ID d\'une balise unique à traiter (sinon : toutes celles du panel)}
        {--days= : Fenêtre glissante en jours (sinon : reliability.window_days)}';

    protected $description = 'Recalcule les MAE et weight_factor des modèles (phase 2.5).';

    public function handle(ReliabilityCalculator $reliability): int
    {
        $query = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true);

        if ($id = $this->option('balise')) {
            $query->where('id', (int) $id);
        }

        $balises = $query->get();
        if ($balises->isEmpty()) {
            $this->warn('Aucune balise dans le panel (active + in_consensus_compare_panel=1).');
            return self::SUCCESS;
        }

        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;
        if ($days !== null) {
            $this->info(sprintf('Fenêtre forcée : %d j', $days));
        }

        $total = 0;
        foreach ($balises as $balise) {
            $t0 = microtime(true);
            $n  = $reliability->recomputeForBalise($balise, $days);
            $dt = (microtime(true) - $t0) * 1000;
            $total += $n;
            $this->line(sprintf(
                '  • %-40s %5d tuples · %6.1f ms',
                substr($balise->name, 0, 40),
                $n,
                $dt
            ));
        }

        $this->info(sprintf('Total : %d tuples upsertés dans model_reliability.', $total));
        return self::SUCCESS;
    }
}
