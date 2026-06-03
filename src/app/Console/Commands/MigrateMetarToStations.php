<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Models\BaliseReading;
use App\Models\StationApi;
use App\Models\WeatherStation;
use App\Models\WeatherStationObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migre les balises METAR (source='metar') vers weather_stations
 * (network='metar') et copie les balise_readings correspondantes
 * vers weather_station_observations.
 *
 * Idempotent : les stations déjà existantes (même external_id) sont
 * mises à jour, les observations en doublon (même station + observed_at)
 * sont ignorées.
 *
 * L'option --seed-apis crée les entrées station_apis (MF, METAR,
 * Infoclimat) si absentes — nécessaire avant toute découverte ou
 * polling de stations.
 */
class MigrateMetarToStations extends Command
{
    protected $signature = 'stations:migrate-metar
        {--seed-apis : Créer les station_apis (MF, METAR, Infoclimat) si absentes}
        {--readings : Migrer aussi les lectures (balise_readings → weather_station_observations)}
        {--chunk=500 : Taille des lots pour l\'insertion des observations}
        {--deactivate : Désactiver les balises METAR source après migration}';

    protected $description = 'Migre les balises METAR vers le système stations météo (+ seed APIs)';

    public function handle(): int
    {
        if ($this->option('seed-apis')) {
            $this->seedStationApis();
        }

        $metarBalises = Balise::where('source', 'metar')->get();

        if ($metarBalises->isEmpty()) {
            $this->warn('Aucune balise METAR trouvée en base.');
            return self::SUCCESS;
        }

        $this->info("Migration de {$metarBalises->count()} balises METAR vers weather_stations…");

        $stationMap = [];
        $created    = 0;
        $updated    = 0;

        foreach ($metarBalises as $balise) {
            $station = WeatherStation::updateOrCreate(
                [
                    'network'     => WeatherStation::NETWORK_METAR,
                    'external_id' => $balise->external_id,
                ],
                [
                    'name'                => $balise->name,
                    'latitude'            => $balise->latitude,
                    'longitude'           => $balise->longitude,
                    'altitude_m'          => $balise->altitude_m,
                    'country_code'        => $balise->country_code,
                    'country'             => $balise->country,
                    'admin_region'        => $balise->admin_region,
                    'department'          => $balise->department,
                    'active'              => $balise->active,
                    'in_reliability_panel' => $balise->in_consensus_compare_panel ?? false,
                ],
            );

            $stationMap[$balise->id] = $station->id;

            if ($station->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        $this->info("  Stations : {$created} créées, {$updated} mises à jour.");

        if ($this->option('readings')) {
            $this->migrateReadings($stationMap, (int) $this->option('chunk'));
        }

        if ($this->option('deactivate')) {
            $deactivated = Balise::where('source', 'metar')
                ->where('active', true)
                ->update(['active' => false]);
            $this->info("  Balises METAR désactivées : {$deactivated}");
        }

        $this->info('Migration METAR terminée.');
        return self::SUCCESS;
    }

    private function migrateReadings(array $stationMap, int $chunkSize): void
    {
        $baliseIds = array_keys($stationMap);
        $total     = BaliseReading::whereIn('balise_id', $baliseIds)->count();

        if ($total === 0) {
            $this->warn('  Aucune lecture METAR à migrer.');
            return;
        }

        $this->info("  Migration de {$total} lectures vers weather_station_observations…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $inserted = 0;
        $skipped  = 0;

        BaliseReading::whereIn('balise_id', $baliseIds)
            ->orderBy('id')
            ->chunk($chunkSize, function ($readings) use ($stationMap, &$inserted, &$skipped, $bar) {
                $rows = [];
                foreach ($readings as $reading) {
                    $stationId = $stationMap[$reading->balise_id] ?? null;
                    if (! $stationId) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    $rows[] = [
                        'weather_station_id' => $stationId,
                        'observed_at'        => $reading->read_at,
                        'wind_direction'     => $reading->wind_direction,
                        'wind_speed_avg'     => $reading->wind_speed_avg,
                        'wind_speed_max'     => $reading->wind_speed_max,
                        'temperature'        => $reading->temperature,
                        'humidity'           => $reading->humidity,
                        'pressure_hpa'       => null,
                        'precipitation_mm'   => null,
                        'cloud_cover_pct'    => null,
                        'visibility_m'       => null,
                        'dew_point'          => null,
                        'raw_data'           => null,
                        'created_at'         => $reading->created_at,
                    ];
                }

                if (! empty($rows)) {
                    try {
                        DB::table('weather_station_observations')->insertOrIgnore($rows);
                        $inserted += count($rows);
                    } catch (\Throwable $e) {
                        $this->error("  Erreur batch : {$e->getMessage()}");
                    }
                }

                $bar->advance(count($readings));
            });

        $bar->finish();
        $this->newLine();
        $this->info("  Observations insérées : {$inserted}, ignorées (doublons) : {$skipped}");

        $this->updateLastObsAt($stationMap);
    }

    private function updateLastObsAt(array $stationMap): void
    {
        $stationIds = array_values($stationMap);

        $latestObs = WeatherStationObservation::whereIn('weather_station_id', $stationIds)
            ->select('weather_station_id', DB::raw('MAX(observed_at) as latest'))
            ->groupBy('weather_station_id')
            ->pluck('latest', 'weather_station_id');

        foreach ($latestObs as $stationId => $latest) {
            WeatherStation::where('id', $stationId)->update(['last_obs_at' => $latest]);
        }
    }

    private function seedStationApis(): void
    {
        $this->info('Seed des station_apis…');

        $apis = [
            [
                'code'      => 'metar',
                'name'      => 'METAR / NOAA',
                'base_url'  => 'https://aviationweather.gov/api/data/metar',
                'auth_type' => 'none',
                'active'    => true,
                'config'    => ['description' => 'Observations aéronautiques NOAA (~200 aérodromes en France).'],
            ],
            [
                'code'      => 'mf',
                'name'      => 'Météo-France',
                'base_url'  => 'https://portail-api.meteofrance.fr',
                'auth_type' => 'oauth2',
                'active'    => false,
                'config'    => [
                    'description' => 'Stations synoptiques et climatologiques (~600 en France métropolitaine).',
                    'token_url'   => 'https://portail-api.meteofrance.fr/token',
                ],
            ],
            [
                'code'      => 'infoclimat',
                'name'      => 'Infoclimat',
                'base_url'  => 'https://www.infoclimat.fr/opendata/',
                'auth_type' => 'api_key',
                'active'    => false,
                'config'    => ['description' => 'Réseau de stations amateurs (~1000+ stations en France).'],
            ],
        ];

        foreach ($apis as $data) {
            $api = StationApi::updateOrCreate(
                ['code' => $data['code']],
                $data,
            );
            $status = $api->wasRecentlyCreated ? 'créée' : 'déjà présente';
            $this->line("  {$data['name']} ({$data['code']}) : {$status}");
        }
    }
}
