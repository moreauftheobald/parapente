<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WeatherModel;
use App\Services\Weather\Reliability\ConsensusCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill ONE-SHOT du consensus (`qui_vole_consensus`) sur les créneaux
 * déjà archivés des stations météo. Pendant du `forecasts:backfill-consensus`
 * (balises). Cf. ce fichier pour le détail de la logique.
 */
class BackfillConsensusStations extends Command
{
    protected $signature = 'forecasts:backfill-consensus-stations
        {--hours=72 : Fenêtre passée à reconstituer (heures)}
        {--station= : Limiter à une station précise}';

    protected $description = 'Backfill one-shot du consensus stations depuis les modèles déjà archivés.';

    /** Champs consensus reconstitués (les autres colonnes restent NULL). */
    private const LINEAR = ['wind_speed_avg', 'wind_speed_max', 'temperature', 'humidity', 'pressure_hpa'];

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

        $stations = DB::table('weather_stations')
            ->where('active', true)
            ->where('has_wind_sensor', true)
            ->when($this->option('station'), fn ($q) => $q->where('id', (int) $this->option('station')))
            ->pluck('id');

        $this->info("Backfill consensus stations : {$stations->count()} station(s), fenêtre {$hours} h.");

        $cols = array_merge(['target_at', 'fetched_at', 'horizon_bucket', 'wind_direction'], self::LINEAR);
        $total = 0;

        foreach ($stations as $stationId) {
            $rows = DB::table('forecast_archive_stations')
                ->where('weather_station_id', $stationId)
                ->where('weather_model_id', '!=', $consensusId)
                ->where('target_at', '>=', $since)
                ->where('target_at', '<=', $until)
                ->orderBy('target_at')
                ->orderByDesc('fetched_at')
                ->get($cols);

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
                $dir = ConsensusCalculator::legacyCircular($this->items($models, 'wind_direction'));
                $row = [
                    'weather_station_id' => $stationId,
                    'weather_model_id'   => $consensusId,
                    'target_at'          => $target,
                    'fetched_at'         => $info['fetched_at'],
                    'horizon_bucket'     => $info['bucket'],
                    'wind_direction'     => $dir !== null ? (int) round($dir) : null,
                    'dew_point'          => null,
                    'precipitation'      => null,
                    'cloud_cover'        => null,
                    'created_at'         => now()->format('Y-m-d H:i:s'),
                    'updated_at'         => now()->format('Y-m-d H:i:s'),
                ];
                $hasValue = $dir !== null;
                foreach (self::LINEAR as $f) {
                    $v = ConsensusCalculator::legacyLinear($this->items($models, $f));
                    $row[$f] = $v !== null ? round($v, 1) : null;
                    $hasValue = $hasValue || $v !== null;
                }
                if (! $hasValue) continue;
                $upserts[] = $row;
            }

            foreach (array_chunk($upserts, 500) as $chunk) {
                DB::table('forecast_archive_stations')->upsert(
                    $chunk,
                    ['weather_station_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                    array_merge(['fetched_at', 'wind_direction', 'dew_point', 'precipitation', 'cloud_cover', 'updated_at'], self::LINEAR)
                );
                $total += count($chunk);
            }
        }

        $this->info("Terminé : {$total} ligne(s) consensus écrite(s).");
        return self::SUCCESS;
    }

    /**
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
