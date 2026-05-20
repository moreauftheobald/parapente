<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Models\BaliseConsensusCompare;
use App\Models\ModelReliability;
use Illuminate\Console\Command;

/**
 * Purge les tuples de `balise_consensus_compare` et `model_reliability`
 * pour les balises **plus présentes dans le panel** (drapeau
 * `in_consensus_compare_panel` repassé à false ou balise désactivée).
 *
 * Les jobs `ComputeBaliseConsensusCompareJob` et
 * `ComputeModelReliabilityJob` font des upsert mais ne suppriment
 * jamais. Sans cette purge, sortir une balise du panel laisse ses
 * vieilles lignes en base — elles ne se mettent plus à jour mais
 * polluent les exports historiques et l'écran de comparaison.
 *
 *   php artisan reliability:purge-out-of-panel              # purge réelle
 *   php artisan reliability:purge-out-of-panel --dry-run    # prévisualisation
 *
 * Idempotent.
 */
class PurgeOutOfPanelReliability extends Command
{
    protected $signature = 'reliability:purge-out-of-panel
        {--dry-run : Compter sans supprimer}';

    protected $description = 'Supprime les tuples de fiabilité pour les balises sorties du panel.';

    public function handle(): int
    {
        $panelIds = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->pluck('id')
            ->all();

        if ($panelIds === []) {
            $this->warn('Panel vide — la purge supprimerait tous les tuples. Annulée par sécurité.');
            $this->line('Ré-active au moins une balise dans le panel avant de relancer.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Identifie les balise_id hors panel ayant encore des lignes
        $outOfPanelCompare = BaliseConsensusCompare::query()
            ->whereNotIn('balise_id', $panelIds)
            ->distinct()
            ->pluck('balise_id')
            ->all();
        $outOfPanelReliab = ModelReliability::query()
            ->whereNotIn('balise_id', $panelIds)
            ->distinct()
            ->pluck('balise_id')
            ->all();
        $outOfPanel = array_unique(array_merge($outOfPanelCompare, $outOfPanelReliab));

        if ($outOfPanel === []) {
            $this->info('Rien à purger : toutes les balises avec données sont dans le panel.');
            return self::SUCCESS;
        }

        // Détail par balise pour le rapport
        $baliseNames = Balise::query()
            ->whereIn('id', $outOfPanel)
            ->pluck('name', 'id')
            ->all();

        $this->info(sprintf(
            '%d balise(s) hors panel avec des données résiduelles :',
            count($outOfPanel)
        ));

        $totalCompareDeleted = 0;
        $totalReliabDeleted  = 0;

        foreach ($outOfPanel as $id) {
            $nCompare = BaliseConsensusCompare::query()->where('balise_id', $id)->count();
            $nReliab  = ModelReliability::query()->where('balise_id', $id)->count();
            $name     = $baliseNames[$id] ?? '(supprimée)';

            $this->line(sprintf(
                '  • #%-4d %-40s · %5d compare · %4d reliability',
                $id,
                substr($name, 0, 40),
                $nCompare,
                $nReliab
            ));

            if (! $dryRun) {
                BaliseConsensusCompare::query()->where('balise_id', $id)->delete();
                ModelReliability::query()->where('balise_id', $id)->delete();
            }

            $totalCompareDeleted += $nCompare;
            $totalReliabDeleted  += $nReliab;
        }

        if ($dryRun) {
            $this->warn(sprintf(
                'DRY-RUN : %d tuples consensus_compare + %d tuples model_reliability auraient été supprimés.',
                $totalCompareDeleted,
                $totalReliabDeleted
            ));
            $this->line('Relance sans --dry-run pour appliquer.');
        } else {
            $this->info(sprintf(
                'Purgé : %d tuples consensus_compare + %d tuples model_reliability.',
                $totalCompareDeleted,
                $totalReliabDeleted
            ));
        }

        return self::SUCCESS;
    }
}
