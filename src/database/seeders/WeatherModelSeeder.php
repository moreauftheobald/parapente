<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WeatherModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // ── Court terme — haute résolution locale ────────────
            [
                'code'          => 'meteofrance_arome_france',
                'name'          => 'AROME',
                'provider'      => 'Météo-France',
                'resolution_km' => 2.5,
                'max_horizon_h' => 48,
                'weight_short'  => 1.00,
                'weight_medium' => 0.00, // indisponible au-delà de 48h
                'active'        => true,
            ],
            [
                'code'          => 'icon_d2',
                'name'          => 'ICON-D2',
                'provider'      => 'DWD',
                'resolution_km' => 2.0,
                'max_horizon_h' => 48,
                'weight_short'  => 1.00,
                'weight_medium' => 0.00,
                'active'        => true,
            ],
            [
                'code'          => 'knmi_harmonie_arome_europe',
                'name'          => 'HARMONIE',
                'provider'      => 'KNMI',
                'resolution_km' => 5.0,
                'max_horizon_h' => 48,
                'weight_short'  => 0.90,
                'weight_medium' => 0.00,
                'active'        => true,
            ],

            // ── Moyen terme — méso-échelle ───────────────────────
            [
                'code'          => 'meteofrance_arpege_europe',
                'name'          => 'ARPEGE-EU',
                'provider'      => 'Météo-France',
                'resolution_km' => 11.0,
                'max_horizon_h' => 96,
                'weight_short'  => 0.80,
                'weight_medium' => 0.90,
                'active'        => true,
            ],
            [
                'code'          => 'icon_eu',
                'name'          => 'ICON-EU',
                'provider'      => 'DWD',
                'resolution_km' => 7.0,
                'max_horizon_h' => 120,
                'weight_short'  => 0.80,
                'weight_medium' => 0.90,
                'active'        => true,
            ],
            [
                'code'          => 'ecmwf_ifs025',
                'name'          => 'IFS-HRES',
                'provider'      => 'ECMWF',
                'resolution_km' => 9.0,
                'max_horizon_h' => 240,
                'weight_short'  => 0.85,
                'weight_medium' => 1.00,
                'active'        => true,
            ],
            [
                'code'          => 'ecmwf_aifs025',
                'name'          => 'AIFS',
                'provider'      => 'ECMWF',
                'resolution_km' => 25.0,
                'max_horizon_h' => 240,
                'weight_short'  => 0.70,
                'weight_medium' => 0.90,
                'active'        => true,
            ],

            // ── Modèles globaux complémentaires ──────────────────
            [
                'code'          => 'icon_seamless',
                'name'          => 'ICON',
                'provider'      => 'DWD',
                'resolution_km' => 13.0,
                'max_horizon_h' => 180,
                'weight_short'  => 0.70,
                'weight_medium' => 0.85,
                'active'        => true,
            ],
            [
                'code'          => 'gem_seamless',
                'name'          => 'GEM',
                'provider'      => 'Environment Canada',
                'resolution_km' => 15.0,
                'max_horizon_h' => 240,
                'weight_short'  => 0.65,
                'weight_medium' => 0.80,
                'active'        => true,
            ],
            [
                'code'          => 'gfs_seamless',
                'name'          => 'GFS',
                'provider'      => 'NOAA',
                'resolution_km' => 25.0,
                'max_horizon_h' => 384,
                'weight_short'  => 0.60,
                'weight_medium' => 0.75,
                'active'        => true,
            ],
        ];

        DB::table('weather_models')->insertOrIgnore($models);
    }
}
