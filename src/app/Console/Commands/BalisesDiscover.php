<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Balise;
use App\Services\Balises\BaliseProviderInterface;
use App\Services\Balises\MetarProvider;
use App\Services\Balises\PiouPiouProvider;
use App\Services\Balises\WindyOpenDataProvider;
use Illuminate\Console\Command;

/**
 * Découverte des balises d'un fournisseur dans une bbox géographique.
 *
 * Usage :
 *   php artisan balises:discover                      # défauts : pioupiou + bbox quart NE France
 *   php artisan balises:discover --source=pioupiou
 *   php artisan balises:discover --lat-min=45 --lat-max=48 --lng-min=5 --lng-max=8
 *
 * Idempotent : relancer la commande met à jour les balises existantes
 * (firstOrNew sur source+external_id) et ajoute les nouvelles. Aucune
 * balise n'est désactivée par cette commande (la désactivation est
 * gérée par le job de polling après inactivité prolongée).
 */
class BalisesDiscover extends Command
{
    protected $signature = 'balises:discover
        {--source=pioupiou : Identifiant du fournisseur (pioupiou, metar, windy)}
        {--lat-min=47.0 : Latitude minimum de la bbox}
        {--lat-max=50.5 : Latitude maximum de la bbox}
        {--lng-min=3.5  : Longitude minimum de la bbox}
        {--lng-max=8.5  : Longitude maximum de la bbox}';

    protected $description = "Découvre les balises d'un fournisseur dans une bbox géographique et les enregistre en base.";

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $latMin = (float) $this->option('lat-min');
        $latMax = (float) $this->option('lat-max');
        $lngMin = (float) $this->option('lng-min');
        $lngMax = (float) $this->option('lng-max');

        $provider = $this->resolveProvider($source);
        if (! $provider) {
            $this->error("Source inconnue : '{$source}' (sources gérées : pioupiou, metar, windy)");
            return self::FAILURE;
        }

        $this->info(sprintf(
            "Découverte balises [%s] dans bbox lat [%.2f → %.2f] × lng [%.2f → %.2f]…",
            $source, $latMin, $latMax, $lngMin, $lngMax
        ));

        $stations = $provider->discoverStations($latMin, $latMax, $lngMin, $lngMax);
        $this->info(count($stations) . ' station(s) éligible(s).');

        $created = 0;
        $updated = 0;

        foreach ($stations as $s) {
            $balise = Balise::firstOrNew([
                'source'      => $source,
                'external_id' => $s['external_id'],
            ]);
            $isNew = ! $balise->exists;

            $balise->fill([
                'name'       => $s['name'],
                'latitude'   => $s['latitude'],
                'longitude'  => $s['longitude'],
                'altitude_m' => $s['altitude_m'],
            ]);
            if (array_key_exists('height_agl_m', $s) && $s['height_agl_m'] !== null) {
                $balise->height_agl_m = (int) $s['height_agl_m'];
            }
            if (! empty($s['reliability_class']) && $balise->reliability_class === null) {
                $balise->reliability_class = $s['reliability_class'];
            }
            // On ne touche pas active=false existant (la désactivation est gérée par le job)
            if ($isNew) {
                $balise->active = true;
            }
            $balise->save();

            $isNew ? $created++ : $updated++;

            $this->line(sprintf(
                '  %s %s (#%s) — %.4f, %.4f',
                $isNew ? '+' : '·',
                $s['name'],
                $s['external_id'],
                $s['latitude'],
                $s['longitude']
            ));
        }

        $this->newLine();
        $this->info("✓ {$created} balise(s) créée(s), {$updated} mise(s) à jour.");
        return self::SUCCESS;
    }

    private function resolveProvider(string $source): ?BaliseProviderInterface
    {
        return match ($source) {
            'pioupiou' => app(PiouPiouProvider::class),
            'metar'    => app(MetarProvider::class),
            'windy'    => app(WindyOpenDataProvider::class),
            default    => null,
        };
    }
}
