<?php


declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SiteSeeder extends Seeder
{
    public function run(): void
    {
        // ── Site : Volmerange EST ────────────────────────────────
        $siteId = DB::table('sites')->insertGetId([
            'name' => 'Volmerange EST',
            'slug' => 'volmerange-est',
            'description' => 'Site de Volmerange-les-Mines, décollage face EST.',
            'region' => 'grand-est',
            'latitude' => 49.4468,
            'longitude' => 6.0999,
            'altitude_m' => 420,
            'landing_lat' => 49.4476,
            'landing_lng' => 6.1101,
            'level' => 'intermediaire',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── Profil de conditions idéales ─────────────────────────
        DB::table('site_conditions')->insert([
            'site_id' => $siteId,

            // Vent EST ± 15° → 75° à 105°
            'wind_dir_min' => 75,
            'wind_dir_max' => 105,

            // Vitesse 0-20 km/h, idéal 10 km/h
            'wind_speed_min' => 0,
            'wind_speed_max' => 20,
            'wind_speed_ideal' => 10,

            // Seuils de pluie : désormais globaux (table settings).
            // Rafales : null = on utilise les seuils globaux.

            // Plafond minimum acceptable : 800m
            'cloud_base_min_m' => 800,

            // Couverture basse couche max : 50%
            'cloud_cover_low_max' => 50,

            'notes' => 'Décollage face EST. Atterrissage à 300m d\'altitude.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
