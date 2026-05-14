<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 13 modèles servis par le serveur Open-Meteo self-hosted dédié.
 *
 * Codes alignés sur la liste OPEN_METEO_MODELS du conteneur dédié :
 * meteofrance_arome_france_hd, meteofrance_arome_france_hd_15min,
 * meteofrance_arpege_europe, dwd_icon_eu, dwd_icon_d2, dwd_icon,
 * ncep_gfs013, ecmwf_ifs025, ecmwf_aifs025_single,
 * ukmo_global_deterministic_10km, bom_access_global,
 * cma_grapes_global, jma_gsm.
 *
 * Désactive automatiquement les modèles d'anciennes intégrations (icon_eu,
 * icon_d2, icon_seamless, gfs_seamless, gem_seamless,
 * knmi_harmonie_arome_europe, meteofrance_arome_france, metno_seamless,
 * dwd_*_native, ecmwf_*_native) si présents en base.
 */
class WeatherModelSeeder extends Seeder
{
    public function run(): void
    {
        $openMeteoId = DB::table('weather_apis')->where('code', 'openmeteo')->value('id');
        if ($openMeteoId === null) {
            throw new \RuntimeException('WeatherApi `openmeteo` introuvable — exécuter WeatherApiSeeder d\'abord.');
        }

        $models = [
            // ── Court terme — haute résolution locale (1.3 km) ───
            [
                'code'                      => 'meteofrance_arome_france_hd',
                'name'                      => 'AROME-HD',
                'provider'                  => 'Météo-France',
                'resolution_km'             => 1.3,
                'max_horizon_h'             => 48,
                'weight_short'              => 1.00,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 180,
                'active'                    => true,
            ],
            [
                'code'                      => 'meteofrance_arome_france_hd_15min',
                'name'                      => 'AROME-HD 15min',
                'provider'                  => 'Météo-France',
                'resolution_km'             => 1.3,
                'max_horizon_h'             => 6,
                'weight_short'              => 1.00,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 60,
                'active'                    => true,
            ],
            [
                'code'                      => 'dwd_icon_d2',
                'name'                      => 'ICON-D2',
                'provider'                  => 'DWD',
                'resolution_km'             => 2.0,
                'max_horizon_h'             => 48,
                'weight_short'              => 1.00,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 180,
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
                'code'                      => 'dwd_icon_eu',
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
                'code'                      => 'ecmwf_aifs025_single',
                'name'                      => 'AIFS Single',
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
                'code'                      => 'dwd_icon',
                'name'                      => 'ICON Global',
                'provider'                  => 'DWD',
                'resolution_km'             => 13.0,
                'max_horizon_h'             => 180,
                'weight_short'              => 0.70,
                'weight_medium'             => 0.85,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'ncep_gfs013',
                'name'                      => 'GFS 0.13°',
                'provider'                  => 'NOAA',
                'resolution_km'             => 13.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.65,
                'weight_medium'             => 0.80,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'ukmo_global_deterministic_10km',
                'name'                      => 'UKMO Global',
                'provider'                  => 'UK Met Office',
                'resolution_km'             => 10.0,
                'max_horizon_h'             => 144,
                'weight_short'              => 0.75,
                'weight_medium'             => 0.85,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'bom_access_global',
                'name'                      => 'ACCESS-G',
                'provider'                  => 'BoM Australia',
                'resolution_km'             => 12.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.55,
                'weight_medium'             => 0.70,
                'refresh_frequency_minutes' => 360,
                'active'                    => false,
            ],
            [
                'code'                      => 'cma_grapes_global',
                'name'                      => 'CMA GRAPES',
                'provider'                  => 'CMA Chine',
                'resolution_km'             => 15.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.55,
                'weight_medium'             => 0.70,
                'refresh_frequency_minutes' => 360,
                'active'                    => false,
            ],
            [
                'code'                      => 'jma_gsm',
                'name'                      => 'JMA GSM',
                'provider'                  => 'JMA Japon',
                'resolution_km'             => 18.0,
                'max_horizon_h'             => 264,
                'weight_short'              => 0.55,
                'weight_medium'             => 0.70,
                'refresh_frequency_minutes' => 360,
                'active'                    => false,
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

        // Désactive les anciens codes obsolètes encore en base.
        $obsoleteCodes = [
            'meteofrance_arome_france',
            'icon_d2',
            'icon_eu',
            'icon_seamless',
            'gfs_seamless',
            'gem_seamless',
            'knmi_harmonie_arome_europe',
            'ecmwf_aifs025',
            'metno_seamless',
            'dwd_icon_eu_native',
            'dwd_icon_global_native',
            'ecmwf_ifs025_native',
            'ecmwf_aifs025_native',
        ];
        DB::table('weather_models')
            ->whereIn('code', $obsoleteCodes)
            ->update(['active' => false, 'updated_at' => now()]);
    }
}
