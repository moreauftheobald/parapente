<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SiteCondition;
use Illuminate\Database\Seeder;

/**
 * Seed du site historique « Volmerange EST » (le tout premier site
 * intégré, avant l'import du GrandEstSitesSeeder). Idempotent :
 * relancer le seeder ne crée pas de doublon (matching par `slug`).
 */
class SiteSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::updateOrCreate(
            ['slug' => 'volmerange-est'],
            [
                'name'        => 'Volmerange EST',
                'description' => 'Site de Volmerange-les-Mines, décollage face EST.',
                'region'      => 'grand-est',
                'latitude'    => 49.4468,
                'longitude'   => 6.0999,
                'altitude_m'  => 420,
                'landing_lat' => 49.4476,
                'landing_lng' => 6.1101,
                'level'       => 'intermediaire',
                'active'      => true,
            ],
        );

        SiteCondition::updateOrCreate(
            ['site_id' => $site->id],
            [
                // Vent EST ± 15° → 75° à 105°
                'wind_dir_min'        => 75,
                'wind_dir_max'        => 105,
                // Vitesse 0-20 km/h, idéal 10 km/h
                'wind_speed_min'      => 0,
                'wind_speed_max'      => 20,
                'wind_speed_ideal'    => 10,
                // Plafond minimum acceptable : 800 m. Couverture basse max 50 %.
                'cloud_base_min_m'    => 800,
                'cloud_cover_low_max' => 50,
                'notes'               => "Décollage face EST. Atterrissage à 300 m d'altitude.",
            ],
        );
    }
}
