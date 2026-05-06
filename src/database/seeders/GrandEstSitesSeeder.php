<?php


declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GrandEstSitesSeeder extends Seeder
{
    /**
     * Sites avec coordonnées GPS valides extraits du fichier Excel.
     * Volmerange EST déjà présent en base — ignoré ici.
     * Sites INTERDIT exclus (Lorry Mardigny).
     * Sites sans GPS exclus (données insuffisantes).
     */
    private array $sites = [
        [
            'name' => 'Jouy sous les Côtes',
            'region' => 'grand-est',
            'department' => 'Meurthe-et-Moselle',
            'lat' => 48.7843,
            'lng' => 5.6803,
            'altitude' => 364,
            'level' => 'debutant',
            'gestionnaire' => 'C.d.v.l. de Meurthe-et-Moselle',
            'notes' => 'Retour à pieds 10min.',
            'wind_dir_ideal' => 67,   // E-NE
            'wind_dir_min' => 45,
            'wind_dir_max' => 90,
        ],
        [
            'name' => 'Beauring',
            'region' => 'grand-est',
            'department' => 'Belgique',
            'lat' => 50.1130,
            'lng' => 5.0093,
            'altitude' => 260,
            'level' => 'intermediaire',
            'gestionnaire' => 'FBVL',
            'notes' => 'Site belge, 1h40 de Thionville. Dénivelé 100m.',
            'wind_dir_ideal' => 0,    // N
            'wind_dir_min' => 330,  // Plage chevauche le Nord
            'wind_dir_max' => 30,
        ],
        [
            'name' => 'Losheim',
            'region' => 'grand-est',
            'department' => 'Allemagne',
            'lat' => 49.5333,
            'lng' => 6.7333,
            'altitude' => 350,
            'level' => 'intermediaire',
            'gestionnaire' => null,
            'notes' => '55min de Thionville.',
            'wind_dir_ideal' => 0,    // N
            'wind_dir_min' => 330,
            'wind_dir_max' => 30,
        ],
        [
            'name' => 'Houéville',
            'region' => 'grand-est',
            'department' => 'Vosges',
            'lat' => 48.3722,
            'lng' => 5.8039,
            'altitude' => 390,
            'level' => 'intermediaire',
            'gestionnaire' => 'Paranormal Gravity',
            'notes' => '1h50 de Thionville.',
            'wind_dir_ideal' => 22,   // N-NE
            'wind_dir_min' => 352,
            'wind_dir_max' => 52,
        ],
        [
            'name' => 'Létanne',
            'region' => 'grand-est',
            'department' => 'Ardennes',
            'lat' => 49.5571,
            'lng' => 5.0601,
            'altitude' => 260,
            'level' => 'intermediaire',
            'gestionnaire' => 'Icarus Club Ardennais',
            'notes' => '1h30 de Thionville. Dénivelé 100m.',
            'wind_dir_ideal' => 45,   // NE
            'wind_dir_min' => 15,
            'wind_dir_max' => 75,
        ],
        [
            'name' => 'Lion-devant-Dun (Côte St Germain)',
            'region' => 'grand-est',
            'department' => 'Meuse',
            'lat' => 49.4105,
            'lng' => 5.2525,
            'altitude' => 318,
            'level' => 'intermediaire',
            'gestionnaire' => null,
            'notes' => '1h20 de Thionville. Dénivelé 120m.',
            'wind_dir_ideal' => 315,  // NO
            'wind_dir_min' => 285,
            'wind_dir_max' => 345,
        ],
        [
            'name' => 'Coo Ouest',
            'region' => 'grand-est',
            'department' => 'Belgique',
            'lat' => 50.39861,
            'lng' => 5.88778,
            'altitude' => 480,
            'level' => 'confirme',
            'gestionnaire' => 'FBVL',
            'notes' => '1h50 de Thionville. Dénivelé 245m.',
            'wind_dir_ideal' => 270,  // O
            'wind_dir_min' => 225,
            'wind_dir_max' => 300,
        ],
        [
            'name' => 'Algrange',
            'region' => 'grand-est',
            'department' => 'Moselle',
            'lat' => 49.359331,
            'lng' => 6.045014,
            'altitude' => 320,
            'level' => 'debutant',
            'gestionnaire' => null,
            'notes' => '10min de Thionville. Retour avec la voile. Dénivelé 80m.',
            'wind_dir_ideal' => 292,  // O-NO
            'wind_dir_min' => 262,
            'wind_dir_max' => 322,
        ],
        [
            'name' => 'Fumay',
            'region' => 'grand-est',
            'department' => 'Ardennes',
            'lat' => 49.9873,
            'lng' => 4.7204,
            'altitude' => 260,
            'level' => 'confirme',
            'gestionnaire' => 'Pointe Ardennes Parapente',
            'notes' => '2h de Thionville. Dénivelé 380m.',
            'wind_dir_ideal' => 247,  // O-SO
            'wind_dir_min' => 217,
            'wind_dir_max' => 277,
        ],
        [
            'name' => 'Coo Sud',
            'region' => 'grand-est',
            'department' => 'Belgique',
            'lat' => 50.39611,
            'lng' => 5.88806,
            'altitude' => 480,
            'level' => 'confirme',
            'gestionnaire' => 'FBVL',
            'notes' => '1h50 de Thionville. Dénivelé 245m.',
            'wind_dir_ideal' => 180,  // S
            'wind_dir_min' => 180,
            'wind_dir_max' => 210,
        ],
        [
            'name' => 'Revin Fallières (Rocroi)',
            'region' => 'grand-est',
            'department' => 'Ardennes',
            'lat' => 49.9483,
            'lng' => 4.6246,
            'altitude' => 370,
            'level' => 'confirme',
            'gestionnaire' => 'FFVL',
            'notes' => '2h15 de Thionville. Dénivelé 240m.',
            'wind_dir_ideal' => 180,  // S
            'wind_dir_min' => 135,
            'wind_dir_max' => 225,
        ],
        [
            'name' => 'Klusserath',
            'region' => 'grand-est',
            'department' => 'Allemagne',
            'lat' => 49.8477,
            'lng' => 6.8712,
            'altitude' => 300,
            'level' => 'intermediaire',
            'gestionnaire' => null,
            'notes' => '1h10 de Thionville. Dénivelé 175m.',
            'wind_dir_ideal' => 202,  // S-SO
            'wind_dir_min' => 172,
            'wind_dir_max' => 232,
        ],
        [
            'name' => 'Markstein',
            'region' => 'grand-est',
            'department' => 'Vosges',
            'lat' => 47.9167,
            'lng' => 7.0167,
            'altitude' => 1206,
            'level' => 'expert',
            'gestionnaire' => 'FFVL',
            'notes' => '3h de Thionville. Dénivelé 726m. Site altitude.',
            'wind_dir_ideal' => 225,  // SO
            'wind_dir_min' => 180,
            'wind_dir_max' => 315,
        ],
    ];

    public function run(): void
    {
        foreach ($this->sites as $siteData) {
            $slug = Str::slug($siteData['name']);

            // Évite les doublons
            if (DB::table('sites')->where('slug', $slug)->exists()) {
                $this->command->line("  Ignoré (existe déjà) : {$siteData['name']}");
                continue;
            }

            $siteId = DB::table('sites')->insertGetId([
                'name' => $siteData['name'],
                'slug' => $slug,
                'description' => "Site de vol {$siteData['department']}. " .
                    ($siteData['gestionnaire'] ? "Gestionnaire : {$siteData['gestionnaire']}." : ''),
                'region' => $siteData['region'],
                'latitude' => $siteData['lat'],
                'longitude' => $siteData['lng'],
                'altitude_m' => $siteData['altitude'],
                'level' => $siteData['level'],
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('site_conditions')->insert([
                'site_id' => $siteId,
                'wind_dir_min' => $siteData['wind_dir_min'],
                'wind_dir_max' => $siteData['wind_dir_max'],
                'wind_speed_min' => 5,
                'wind_speed_max' => 30,
                'wind_speed_ideal' => 15,
                'precip_max' => 0.0,
                'cloud_base_min_m' => 500,
                'cloud_cover_low_max' => 60,
                'notes' => $siteData['notes'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->command->line("  ✓ Importé : {$siteData['name']}");
        }

        $this->command->info('Import terminé — ' . count($this->sites) . ' sites traités.');
    }
}
