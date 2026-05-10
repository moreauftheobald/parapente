<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WeatherModelSeeder extends Seeder
{
    public function run(): void
    {
        // L'API par défaut pour tous les modèles existants reste Open-Meteo.
        // L'admin peut basculer ensuite vers Météo-France / DWD / ECMWF
        // selon les capacités déclarées par chaque WeatherApi (voir
        // App\Services\Weather\Apis\*::supportedModelCodes()).
        $openMeteoId = DB::table('weather_apis')->where('code', 'openmeteo')->value('id');

        $models = [
            // ── Court terme — haute résolution locale ────────────
            [
                'code'                      => 'meteofrance_arome_france',
                'name'                      => 'AROME',
                'provider'                  => 'Météo-France',
                'resolution_km'             => 2.5,
                'max_horizon_h'             => 48,
                'weight_short'              => 1.00,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 180, // run 8x/jour (toutes 3h)
                'active'                    => true,
            ],
            [
                'code'                      => 'icon_d2',
                'name'                      => 'ICON-D2',
                'provider'                  => 'DWD',
                'resolution_km'             => 2.0,
                'max_horizon_h'             => 48,
                'weight_short'              => 1.00,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 180, // run 8x/jour
                'active'                    => true,
            ],
            [
                'code'                      => 'knmi_harmonie_arome_europe',
                'name'                      => 'HARMONIE',
                'provider'                  => 'KNMI',
                'resolution_km'             => 5.0,
                'max_horizon_h'             => 48,
                'weight_short'              => 0.90,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 360, // run 4x/jour
                'active'                    => true,
            ],

            // ── Moyen terme — méso-échelle ───────────────────────
            [
                'code'                      => 'meteofrance_arpege_europe',
                'name'                      => 'ARPEGE-EU',
                'provider'                  => 'Météo-France',
                'resolution_km'             => 11.0,
                'max_horizon_h'             => 96,
                'weight_short'              => 0.80,
                'weight_medium'             => 0.90,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'icon_eu',
                'name'                      => 'ICON-EU',
                'provider'                  => 'DWD',
                'resolution_km'             => 7.0,
                'max_horizon_h'             => 120,
                'weight_short'              => 0.80,
                'weight_medium'             => 0.90,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'ecmwf_ifs025',
                'name'                      => 'IFS-HRES',
                'provider'                  => 'ECMWF',
                'resolution_km'             => 9.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.85,
                'weight_medium'             => 1.00,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'ecmwf_aifs025',
                'name'                      => 'AIFS',
                'provider'                  => 'ECMWF',
                'resolution_km'             => 25.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.70,
                'weight_medium'             => 0.90,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],

            // ── Modèles globaux complémentaires ──────────────────
            [
                'code'                      => 'icon_seamless',
                'name'                      => 'ICON',
                'provider'                  => 'DWD',
                'resolution_km'             => 13.0,
                'max_horizon_h'             => 180,
                'weight_short'              => 0.70,
                'weight_medium'             => 0.85,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'gem_seamless',
                'name'                      => 'GEM',
                'provider'                  => 'Environment Canada',
                'resolution_km'             => 15.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.65,
                'weight_medium'             => 0.80,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'gfs_seamless',
                'name'                      => 'GFS',
                'provider'                  => 'NOAA',
                'resolution_km'             => 25.0,
                'max_horizon_h'             => 384,
                'weight_short'              => 0.60,
                'weight_medium'             => 0.75,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
        ];

        foreach ($models as $model) {
            DB::table('weather_models')->updateOrInsert(
                ['code' => $model['code']],
                array_merge($model, [
                    'weather_api_id' => $openMeteoId,
                    'updated_at'     => now(),
                    'created_at'     => now(),
                ])
            );
        }

        // ── Modèle alternatif servi par MET Norway (inactif par défaut) ──
        // À activer dans l'admin si l'on souhaite l'utiliser comme source
        // complémentaire pour la voting logic.
        $metnoApiId = DB::table('weather_apis')->where('code', 'metno')->value('id');
        DB::table('weather_models')->updateOrInsert(
            ['code' => 'metno_seamless'],
            [
                'name'                      => 'MET Norway (MEPS+IFS)',
                'provider'                  => 'MET Norway',
                'weather_api_id'            => $metnoApiId,
                'resolution_km'             => 2.5,
                'max_horizon_h'             => 60,
                'weight_short'              => 0.85,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 180,
                'active'                    => false,
                'updated_at'                => now(),
                'created_at'                => now(),
            ]
        );
    }
}
