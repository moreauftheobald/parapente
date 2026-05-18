<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Services\Weather\Reliability\BaliseConsensusCompareService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Calcule en synchrone les 3 consensus (A legacy / B amélioré /
 * C amélioré+fiabilité) pour les balises du panel de test fiabilité
 * (phase 2.5).
 *
 * Sert d'utilitaire de mise au point en attendant le job horaire
 * `ComputeBaliseConsensusCompareJob` (commit suivant).
 *
 *   php artisan reliability:compute-compare          # toutes les balises du panel, fenêtre ±72h
 *   php artisan reliability:compute-compare --balise=123
 *   php artisan reliability:compute-compare --hours=24
 *
 * Idempotent (upsert sur clé unique). Honore le kill switch global
 * `reliability.shadow_enabled` — le service retourne 0 immédiatement
 * si désactivé.
 */
class ComputeConsensusCompare extends Command
{
    protected $signature = 'reliability:compute-compare
        {--balise= : ID d\'une balise unique à traiter (sinon : toutes celles du panel)}
        {--hours=72 : Demi-fenêtre en heures autour de maintenant (passé + futur)}';

    protected $description = 'Calcule les 3 consensus comparatifs sur la fenêtre demandée (phase 2.5 shadow).';

    public function handle(BaliseConsensusCompareService $service): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $from  = Carbon::now()->subHours($hours)->startOfHour();
        $to    = Carbon::now()->addHours($hours)->startOfHour();

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

        $this->info(sprintf(
            'Fenêtre %s → %s (%d h totale)',
            $from->format('Y-m-d H:i'),
            $to->format('Y-m-d H:i'),
            $hours * 2
        ));

        $total = 0;
        foreach ($balises as $balise) {
            $t0 = microtime(true);
            $n  = $service->computeForBalise($balise, $from, $to);
            $dt = (microtime(true) - $t0) * 1000;
            $total += $n;
            $this->line(sprintf(
                '  • %-40s %5d tuples · %6.1f ms',
                substr($balise->name, 0, 40),
                $n,
                $dt
            ));
        }

        $this->info(sprintf('Total : %d tuples upsertés.', $total));
        return self::SUCCESS;
    }
}
