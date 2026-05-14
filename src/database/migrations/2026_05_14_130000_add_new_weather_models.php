<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Insère 6 nouveaux modèles désormais servis par notre instance
 * Open-Meteo self-hosted :
 *   - meteofrance_arome_france0025  (AROME 0.025°, ~2.5 km, 51h)
 *   - cmc_gem_gdps                  (CMC GDPS Canada, 15 km, 240h)
 *   - ncep_gfs_graphcast025         (GraphCast IA, 25 km, 240h)
 *   - ncep_aigfs025                 (AI-GFS NOAA, 25 km, 240h)
 *   - ncep_aigefs025                (AI-GEFS ensemble — inactif, source serveur vide)
 *   - ncep_hgefs025_ensemble_mean   (HGEFS ensemble mean — inactif, trop lissé)
 *
 * Idempotent : updateOrInsert sur le code. Tous bound sur l'API
 * `openmeteo` (self-hosted). Configuration alignée sur le
 * WeatherModelSeeder afin qu'un futur reseed reproduise l'état.
 */
return new class extends Migration
{
    public function up(): void
    {
        $openMeteoId = DB::table('weather_apis')->where('code', 'openmeteo')->value('id');
        if ($openMeteoId === null) {
            // Pas d'API self-hosted → on n'a rien à brancher
            return;
        }

        $models = [
            [
                'code'                      => 'meteofrance_arome_france0025',
                'name'                      => 'AROME 0.025°',
                'provider'                  => 'Météo-France',
                'resolution_km'             => 2.5,
                'max_horizon_h'             => 51,
                'weight_short'              => 0.95,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 180,
                'active'                    => true,
            ],
            [
                'code'                      => 'cmc_gem_gdps',
                'name'                      => 'GEM GDPS',
                'provider'                  => 'CMC Canada',
                'resolution_km'             => 15.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.65,
                'weight_medium'             => 0.80,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'ncep_gfs_graphcast025',
                'name'                      => 'GraphCast',
                'provider'                  => 'NOAA / DeepMind',
                'resolution_km'             => 25.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.60,
                'weight_medium'             => 0.80,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'ncep_aigfs025',
                'name'                      => 'AI-GFS',
                'provider'                  => 'NOAA',
                'resolution_km'             => 25.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.55,
                'weight_medium'             => 0.75,
                'refresh_frequency_minutes' => 360,
                'active'                    => true,
            ],
            [
                'code'                      => 'ncep_aigefs025',
                'name'                      => 'AI-GEFS ens.',
                'provider'                  => 'NOAA',
                'resolution_km'             => 25.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.50,
                'weight_medium'             => 0.65,
                'refresh_frequency_minutes' => 360,
                'active'                    => false,
            ],
            [
                'code'                      => 'ncep_hgefs025_ensemble_mean',
                'name'                      => 'HGEFS ens. mean',
                'provider'                  => 'NOAA',
                'resolution_km'             => 25.0,
                'max_horizon_h'             => 240,
                'weight_short'              => 0.50,
                'weight_medium'             => 0.65,
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
    }

    public function down(): void
    {
        DB::table('weather_models')->whereIn('code', [
            'meteofrance_arome_france0025',
            'cmc_gem_gdps',
            'ncep_gfs_graphcast025',
            'ncep_aigfs025',
            'ncep_aigefs025',
            'ncep_hgefs025_ensemble_mean',
        ])->delete();
    }
};
