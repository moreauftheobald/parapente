<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sites\ParaglidingEarthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importe les sites de vol d'un pays depuis ParaglidingEarth.
 *
 * Usage :
 *   php artisan sites:import                 # France complète par défaut
 *   php artisan sites:import --iso=ch
 *   php artisan sites:import --iso=fr --limit=10 --dry-run
 *
 * Comportement :
 *   - Crée les sites avec source='paraglidingearth', external_id=pge_site_id,
 *     active=false (l'utilisateur les activera depuis le BackOffice à venir,
 *     pour ne pas exploser la consommation Open-Meteo).
 *   - Idempotent : skip les sites déjà importés (clé unique source+external_id).
 *   - Slug suffixé avec l'external_id pour éviter les collisions.
 *   - Crée également l'entrée site_conditions associée avec valeurs par
 *     défaut conservatrices (que l'utilisateur ajustera).
 *   - --dry-run : affiche ce qui serait fait sans rien écrire en base.
 */
class SitesImport extends Command
{
    protected $signature = 'sites:import
        {--iso=fr : Code ISO 2 lettres du pays (fr, ch, …)}
        {--limit= : Limite optionnelle (utile pour tester)}
        {--dry-run : N\'écrit rien en base, affiche juste ce qui serait fait}';

    protected $description = "Importe les sites de vol d'un pays depuis ParaglidingEarth";

    public function handle(ParaglidingEarthService $service): int
    {
        $iso     = strtolower((string) $this->option('iso'));
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun  = (bool) $this->option('dry-run');

        $this->info(sprintf(
            "Import sites ParaglidingEarth [iso=%s%s]%s …",
            $iso,
            $limit ? ", limit={$limit}" : '',
            $dryRun ? ' [DRY-RUN]' : ''
        ));

        $sites = $service->fetchCountrySites($iso, $limit);
        $this->info(count($sites) . ' site(s) parapente takeoff retournés par PGE.');

        if ($dryRun) {
            $this->warn('Mode dry-run : rien ne sera écrit en base.');
        }

        $created = 0;
        $skipped = 0;

        foreach ($sites as $s) {
            $exists = DB::table('sites')
                ->where('source', 'paraglidingearth')
                ->where('external_id', $s['external_id'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            $slug = Str::slug($s['name']) . '-pge-' . $s['external_id'];

            if ($dryRun) {
                $this->line("  + {$s['name']} (#{$s['external_id']}) — {$s['latitude']}, {$s['longitude']}");
                $created++;
                continue;
            }

            DB::transaction(function () use ($s, $slug) {
                $siteId = DB::table('sites')->insertGetId([
                    'name'        => $s['name'],
                    'slug'        => $slug,
                    'description' => $s['description'],
                    'region'      => null, // PGE ne fournit pas de région française
                    'source'      => 'paraglidingearth',
                    'external_id' => $s['external_id'],
                    'latitude'    => $s['latitude'],
                    'longitude'   => $s['longitude'],
                    'altitude_m'  => $s['altitude_m'],
                    'landing_lat' => $s['landing_lat'],
                    'landing_lng' => $s['landing_lng'],
                    'level'       => 'intermediaire',
                    'active'      => false, // doit être activé manuellement
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                DB::table('site_conditions')->insert([
                    'site_id'              => $siteId,
                    'wind_dir_min'         => $s['wind_dir_min'] ?? 0,
                    'wind_dir_max'         => $s['wind_dir_max'] ?? 360,
                    'wind_speed_min'       => 0,
                    'wind_speed_max'       => 25,
                    'wind_speed_ideal'     => 12,
                    'precip_max'           => 0.0,
                    'cloud_base_min_m'     => 800,
                    'cloud_cover_low_max'  => 50,
                    'notes'                => $s['pge_link'] ? "Source : {$s['pge_link']}" : null,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            });

            $created++;
        }

        $this->newLine();
        $this->info(sprintf(
            "✓ %d site(s) %s, %d ignoré(s) (déjà importés).",
            $created,
            $dryRun ? 'à créer' : 'créé(s)',
            $skipped
        ));

        return self::SUCCESS;
    }
}
