<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WeatherStation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClassifyStationSensors extends Command
{
    protected $signature = 'stations:classify-sensors
        {--network= : Filtrer par réseau (mf, metar, infoclimat)}
        {--days=3 : Nombre de jours d\'observations à analyser}
        {--dry-run : Afficher les changements sans mettre à jour}';

    protected $description = 'Analyse les observations récentes pour classifier has_wind_sensor sur les stations';

    public function handle(): int
    {
        $network = $this->option('network');
        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $since = Carbon::now()->subDays($days);

        if ($network && ! array_key_exists($network, WeatherStation::NETWORKS)) {
            $this->error("Réseau inconnu « {$network} ». Valeurs possibles : " . implode(', ', array_keys(WeatherStation::NETWORKS)));
            return self::FAILURE;
        }

        // 1. Load active stations
        $stationsQuery = WeatherStation::query()->active();
        if ($network) {
            $stationsQuery->network($network);
        }
        $stations = $stationsQuery->get(['id', 'network', 'has_wind_sensor']);

        if ($stations->isEmpty()) {
            $this->warn('Aucune station active trouvée.');
            return self::SUCCESS;
        }

        $stationIds = $stations->pluck('id');
        $this->info("Stations actives : {$stations->count()}");
        $this->info("Période d'analyse : {$days} jour(s) (depuis {$since->format('Y-m-d H:i')})");
        if ($dryRun) {
            $this->warn('Mode dry-run — aucune mise à jour ne sera effectuée.');
        }
        $this->newLine();

        // 2. One aggregate query: count total obs + count obs with wind data per station
        $windCounts = DB::table('weather_station_observations')
            ->select([
                'weather_station_id',
                DB::raw('COUNT(*) as total_obs'),
                DB::raw('SUM(CASE WHEN wind_speed_avg IS NOT NULL THEN 1 ELSE 0 END) as wind_obs'),
            ])
            ->whereIn('weather_station_id', $stationIds)
            ->where('observed_at', '>=', $since)
            ->groupBy('weather_station_id')
            ->get()
            ->keyBy('weather_station_id');

        // 3. Classify stations
        $withWind = [];
        $withoutWind = [];
        $unchanged = [];

        $bar = $this->output->createProgressBar($stations->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');

        foreach ($stations as $station) {
            $stats = $windCounts->get($station->id);

            if (! $stats || $stats->total_obs === 0) {
                // No observations → leave unchanged
                $unchanged[] = $station;
            } elseif ($stats->wind_obs > 0) {
                $withWind[] = $station->id;
            } else {
                $withoutWind[] = $station->id;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // 4. Batch updates
        if (! $dryRun) {
            foreach (array_chunk($withWind, 500) as $chunk) {
                WeatherStation::whereIn('id', $chunk)->update(['has_wind_sensor' => true]);
            }
            foreach (array_chunk($withoutWind, 500) as $chunk) {
                WeatherStation::whereIn('id', $chunk)->update(['has_wind_sensor' => false]);
            }
        }

        // 5. Summary table by network
        $stationsByNetwork = $stations->groupBy('network');
        $withWindSet = array_flip($withWind);
        $withoutWindSet = array_flip($withoutWind);

        $rows = [];
        foreach ($stationsByNetwork as $net => $netStations) {
            $netWithWind = $netStations->filter(fn ($s) => isset($withWindSet[$s->id]))->count();
            $netWithoutWind = $netStations->filter(fn ($s) => isset($withoutWindSet[$s->id]))->count();
            $netUnchanged = $netStations->count() - $netWithWind - $netWithoutWind;

            $rows[] = [
                WeatherStation::NETWORKS[$net]['label'] ?? $net,
                $netStations->count(),
                $netWithWind,
                $netWithoutWind,
                $netUnchanged,
            ];
        }

        // Total row
        $rows[] = [
            'TOTAL',
            $stations->count(),
            count($withWind),
            count($withoutWind),
            count($unchanged),
        ];

        $this->table(
            ['Réseau', 'Total stations', 'Avec vent', 'Sans vent', 'Inchangées'],
            $rows,
        );

        $action = $dryRun ? 'seraient mises à jour' : 'mises à jour';
        $this->info("Classification terminée — " . (count($withWind) + count($withoutWind)) . " station(s) {$action}.");

        return self::SUCCESS;
    }
}
