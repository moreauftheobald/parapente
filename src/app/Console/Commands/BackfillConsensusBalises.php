<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Models\WeatherModel;
use App\Services\Weather\Reliability\ConsensusCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill ONE-SHOT du consensus (`qui_vole_consensus`) sur les créneaux
 * déjà archivés des balises.
 *
 * Normalement le consensus vient du sidecar (job d'archivage). Mais le
 * sidecar ne sert que le run courant → il ne peut pas fournir le consensus
 * PASSÉ (J−2 → maintenant) jamais archivé. Cette commande le reconstitue
 * UNE fois, à partir des modèles déjà archivés, via la formule consensus
 * du repo (ConsensusCalculator legacy, poids égaux). Exception assumée.
 */
class BackfillConsensusBalises extends Command
{
    protected $signature = 'forecasts:backfill-consensus
        {--hours=72 : Fenêtre passée à reconstituer (heures)}
        {--balise= : Limiter à une balise précise}';

    protected $description = 'Backfill one-shot du consensus balises depuis les modèles déjà archivés (le passé que le sidecar ne peut pas resservir).';

    public function handle(): int
    {
        $consensusId = WeatherModel::where('code', WeatherModel::CONSENSUS_CODE)->value('id');
        if ($consensusId === null) {
            $this->error('Modèle qui_vole_consensus absent — lancer WeatherModelSeeder.');
            return self::FAILURE;
        }

        $hours = (int) $this->option('hours');
        $since = Carbon::now()->subHours($hours);
        $until = Carbon::now();

        $balises = Balise::active()
            ->when($this->option('balise'), fn ($q) => $q->where('id', (int) $this->option('balise')))
            ->get(['id']);

        $this->info("Backfill consensus : {$balises->count()} balise(s), fenêtre {$hours} h.");

        $total = 0;
        foreach ($balises as $balise) {
            $rows = DB::table('forecast_archive_balises')
                ->where('balise_id', $balise->id)
                ->where('weather_model_id', '!=', $consensusId)
                ->where('target_at', '>=', $since)
                ->where('target_at', '<=', $until)
                ->orderBy('target_at')
                ->orderByDesc('fetched_at')
                ->get(['target_at', 'fetched_at', 'horizon_bucket', 'wind_direction', 'wind_speed_avg', 'wind_speed_min', 'wind_speed_max', 'temperature']);

            // Par créneau : garder uniquement le dernier run archivé (max fetched_at).
            $byTarget = [];
            foreach ($rows as $r) {
                $t = $r->target_at;
                if (! isset($byTarget[$t])) {
                    $byTarget[$t] = ['fetched_at' => $r->fetched_at, 'bucket' => $r->horizon_bucket, 'models' => []];
                }
                if ($r->fetched_at === $byTarget[$t]['fetched_at']) {
                    $byTarget[$t]['models'][] = $r;
                }
            }

            $upserts = [];
            foreach ($byTarget as $target => $info) {
                $models = $info['models'];
                $avg = ConsensusCalculator::legacyLinear($this->items($models, 'wind_speed_avg'));
                $max = ConsensusCalculator::legacyLinear($this->items($models, 'wind_speed_max'));
                $dir = ConsensusCalculator::legacyCircular($this->items($models, 'wind_direction'));
                $tmp = ConsensusCalculator::legacyLinear($this->items($models, 'temperature'));
                if ($avg === null && $dir === null) {
                    continue;
                }
                $upserts[] = [
                    'balise_id'        => $balise->id,
                    'weather_model_id' => $consensusId,
                    'target_at'        => $target,
                    'fetched_at'       => $info['fetched_at'],
                    'horizon_bucket'   => $info['bucket'],
                    'wind_direction'   => $dir !== null ? (int) round($dir) : 0,
                    'wind_speed_avg'   => $avg !== null ? round($avg, 1) : 0,
                    'wind_speed_min'   => $avg !== null ? round($avg, 1) : 0,
                    'wind_speed_max'   => $max !== null ? round($max, 1) : 0,
                    'temperature'      => $tmp !== null ? round($tmp, 1) : 0,
                    'created_at'       => now()->format('Y-m-d H:i:s'),
                    'updated_at'       => now()->format('Y-m-d H:i:s'),
                ];
            }

            foreach (array_chunk($upserts, 500) as $chunk) {
                DB::table('forecast_archive_balises')->upsert(
                    $chunk,
                    ['balise_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                    ['fetched_at', 'wind_direction', 'wind_speed_avg', 'wind_speed_min', 'wind_speed_max', 'temperature', 'updated_at']
                );
                $total += count($chunk);
            }
        }

        $this->info("Terminé : {$total} ligne(s) consensus écrite(s).");
        return self::SUCCESS;
    }

    /**
     * Construit la liste d'items {value, weight} pour ConsensusCalculator,
     * poids égaux, en ignorant les valeurs nulles.
     *
     * @param array<int,object> $models
     * @return array<int,array{value:float,weight:float}>
     */
    private function items(array $models, string $field): array
    {
        $items = [];
        foreach ($models as $m) {
            if ($m->{$field} !== null) {
                $items[] = ['value' => (float) $m->{$field}, 'weight' => 1.0];
            }
        }
        return $items;
    }
}
