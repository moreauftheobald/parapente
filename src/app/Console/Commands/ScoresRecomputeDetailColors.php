<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteScore;
use App\Services\Weather\ScoringService;
use Illuminate\Console\Command;

/**
 * Recalcule les couleurs par paramètre (`detail.*.color`) des site_scores
 * déjà en base, sans refetch des prévisions météo.
 *
 * Sert au backfill après l'ajout de la voting logic détaillée : les scores
 * existants ont leur `detail` mais sans le sous-champ `color`. Cette
 * commande lit les consensus stockés dans `detail` + les `precip.values`
 * (déjà persistées) et applique les règles de coloration de
 * ScoringService::computeParamColors().
 *
 * Idempotent : on peut relancer la commande sans dommage.
 */
class ScoresRecomputeDetailColors extends Command
{
    protected $signature = 'scores:recompute-detail-colors
        {--site= : Limiter à un site (id)}
        {--chunk=500 : Taille de lot pour le traitement}';

    protected $description = "Recalcule les couleurs par paramètre (detail.*.color) des site_scores existants.";

    public function handle(ScoringService $scoring): int
    {
        $siteId = $this->option('site');
        $chunk  = (int) $this->option('chunk');
        if ($chunk < 1) $chunk = 500;

        $query = SiteScore::query()->orderBy('id');
        if ($siteId !== null) {
            $query->where('site_id', (int) $siteId);
        }

        // Pré-charge les conditions des sites pour éviter N+1
        $siteIds    = $query->clone()->select('site_id')->distinct()->pluck('site_id');
        $conditions = Site::with('conditions')
            ->whereIn('id', $siteIds)
            ->get()
            ->keyBy('id');

        $total      = $query->clone()->count();
        $processed  = 0;
        $updated    = 0;
        $skipped    = 0;

        $this->info("Traitement de {$total} score(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunk, function ($scores) use ($scoring, $conditions, &$processed, &$updated, &$skipped, $bar) {
            foreach ($scores as $score) {
                $processed++;
                $bar->advance();

                $detail = $score->detail;
                $site   = $conditions->get($score->site_id);
                if (! $detail || ! $site || ! $site->conditions) {
                    $skipped++;
                    continue;
                }

                $windDir   = $detail['wind_dir']['consensus']   ?? null;
                $windSpeed = $detail['wind_speed']['consensus'] ?? null;
                $windGust  = $detail['wind_gust']['consensus']  ?? null;
                $precip    = $detail['precip']['consensus']     ?? null;
                $cloudBase = $detail['cloud_base']['consensus'] ?? null;
                $precipVals = $detail['precip']['values']       ?? [];

                if ($windDir === null || $windSpeed === null || $windGust === null || $precip === null) {
                    $skipped++;
                    continue;
                }

                $colors = $scoring->computeParamColors(
                    $site->conditions,
                    (float) $windDir,
                    (float) $windSpeed,
                    (float) $windGust,
                    (float) $precip,
                    is_array($precipVals) ? $precipVals : [],
                    $cloudBase !== null ? (float) $cloudBase : null,
                );

                $detail['wind_dir']['color']   = $colors['wind_dir'];
                $detail['wind_speed']['color'] = $colors['wind_speed'];
                $detail['wind_gust']['color']  = $colors['wind_gust'];
                $detail['precip']['color']     = $colors['precip'];
                $detail['cloud_base']['color'] = $colors['cloud_base'];

                $score->detail = $detail;
                $score->save();
                $updated++;
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Terminé : {$updated} mis à jour, {$skipped} ignorés sur {$processed} parcourus.");

        return self::SUCCESS;
    }
}
