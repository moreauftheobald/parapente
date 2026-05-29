<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 19 modèles servis par le serveur Open-Meteo self-hosted dédié + 1
 * routé sur l'API publique (UKMO Global — la self-hosted n'expose pas
 * son vent à 10 m). Plus 1 régional Amérique du Nord (cmc_gem_rdps)
 * en base mais inactif tant qu'il n'y a pas de site canadien.
 *
 * Codes alignés sur la liste OPEN_METEO_MODELS du conteneur dédié :
 *   Météo-France : arome_france_hd, arome_france_hd_15min,
 *                  arome_france0025, arpege_europe
 *   DWD          : icon, icon_eu, icon_d2
 *   NOAA/NCEP    : gfs013, gfs_graphcast025, aigfs025, aigefs025,
 *                  hgefs025_ensemble_mean
 *   ECMWF        : ifs025, aifs025_single
 *   Autres       : ukmo_global_deterministic_10km, cmc_gem_gdps,
 *                  cmc_gem_rdps (inactif),
 *                  bom_access_global, cma_grapes_global, jma_gsm
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
                // Inactif : modèle régional Amérique du Nord — ne couvre
                // pas l'Europe. À activer si des sites canadiens sont
                // ajoutés au projet.
                'code'                      => 'cmc_gem_rdps',
                'name'                      => 'GEM RDPS',
                'provider'                  => 'CMC Canada',
                'resolution_km'             => 10.0,
                'max_horizon_h'             => 84,
                'weight_short'              => 0.80,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 360,
                'active'                    => false,
            ],

            // ── Modèles IA / hybrides ────────────────────────────
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
                // Inactif : sur le serveur self-hosted la source ne
                // produit pas encore de données utilisables (~12 K).
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
                // Inactif : ensemble mean = trop lissé pour parapente
                // (les pics utiles disparaissent dans la moyenne).
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

        // Consensus Qui-Vole — modèle servi par le sidecar
        // parapente-consensus-grid (API `consensus`), pas par l'instance
        // Open-Meteo. Le ScoringService l'utilise en priorité comme valeur
        // de consensus (cf. ConsensusApi / ScoringService).
        $consensusApiId = DB::table('weather_apis')->where('code', 'consensus')->value('id');
        if ($consensusApiId !== null) {
            DB::table('weather_models')->updateOrInsert(
                ['code' => 'qui_vole_consensus'],
                [
                    'name'                      => 'Consensus Qui-Vole',
                    'provider'                  => 'Qui-Vole',
                    'weather_api_id'            => $consensusApiId,
                    'resolution_km'             => 2.5,
                    'max_horizon_h'             => 120,
                    'weight_short'              => 1.00,
                    'weight_medium'             => 1.00,
                    'refresh_frequency_minutes' => 60,
                    'active'                    => true,
                    'updated_at'                => now(),
                    'created_at'                => now(),
                ]
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
