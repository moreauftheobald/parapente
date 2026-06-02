<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WeatherStation;
use App\Services\Stations\InfoclimatStationProvider;
use App\Services\Stations\MetarStationProvider;
use App\Services\Stations\MfStationProvider;
use App\Services\Stations\StationProviderInterface;
use Illuminate\Console\Command;

/**
 * Découvre les stations météo d'un réseau dans une bbox géographique
 * et les insère/met à jour dans weather_stations.
 *
 * Idempotent : les stations existantes (même network + external_id)
 * sont mises à jour, les nouvelles sont créées actives.
 */
class WeatherStationsDiscover extends Command
{
    protected $signature = 'weather-stations:discover
        {--network=mf : Réseau (mf, metar, infoclimat)}
        {--lat-min=42.0 : Latitude minimale}
        {--lat-max=51.5 : Latitude maximale}
        {--lng-min=-5.5 : Longitude minimale}
        {--lng-max=10.0 : Longitude maximale}';

    protected $description = 'Découvre les stations météo d\'un réseau dans une bbox';

    public function handle(): int
    {
        $network = $this->option('network');
        $provider = $this->resolveProvider($network);

        if (! $provider) {
            $this->error("Réseau inconnu : {$network}");
            return self::FAILURE;
        }

        $latMin = (float) $this->option('lat-min');
        $latMax = (float) $this->option('lat-max');
        $lngMin = (float) $this->option('lng-min');
        $lngMax = (float) $this->option('lng-max');

        $this->info("Découverte [{$network}] dans bbox [{$latMin},{$lngMin}] → [{$latMax},{$lngMax}]…");

        try {
            $stations = $provider->discoverStations($latMin, $latMax, $lngMin, $lngMax);
        } catch (\Throwable $e) {
            $this->error("Erreur : {$e->getMessage()}");
            return self::FAILURE;
        }

        if (empty($stations)) {
            $this->warn('Aucune station trouvée. Consultez les logs (storage/logs/laravel.log) pour le détail.');
            return self::SUCCESS;
        }

        $this->info(count($stations) . ' stations trouvées, insertion/mise à jour…');

        $created = 0;
        $updated = 0;

        foreach ($stations as $s) {
            $station = WeatherStation::firstOrNew([
                'network'     => $network,
                'external_id' => $s['external_id'],
            ]);

            $station->fill([
                'name'       => $s['name'],
                'latitude'   => $s['latitude'],
                'longitude'  => $s['longitude'],
                'altitude_m' => $s['altitude_m'] ?? null,
            ]);

            if (! $station->exists) {
                $station->active = true;
                $station->save();
                $created++;
            } else {
                $station->save();
                $updated++;
            }
        }

        $this->info("Terminé : {$created} créées, {$updated} mises à jour.");

        return self::SUCCESS;
    }

    private function resolveProvider(string $network): ?StationProviderInterface
    {
        return match ($network) {
            'mf'         => app(MfStationProvider::class),
            'metar'      => app(MetarStationProvider::class),
            'infoclimat' => app(InfoclimatStationProvider::class),
            default      => null,
        };
    }
}
