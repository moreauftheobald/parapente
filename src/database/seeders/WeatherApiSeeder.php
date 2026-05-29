<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * WeatherApiSeeder — pile self-hosted.
 *
 * Le projet utilise un serveur Open-Meteo dédié (https://github.com/
 * open-meteo/open-meteo) qui agrège 13 modèles publics (MF AROME/ARPEGE,
 * DWD ICON, ECMWF, GFS, UKMO, BOM, CMA, JMA). URL paramétrable via
 * l'env `OPEN_METEO_BASE_URL` (défaut http://localhost:8888/v1 en dev,
 * http://open-meteo-api:8080/v1 en prod docker-compose).
 *
 * Les anciennes intégrations natives (MF DPS, DWD OpenData, ECMWF Open
 * Data, MET Norway) ont été supprimées au profit de cette unique source.
 * Pour réintroduire un fournisseur, repartir de l'historique git PR4.
 */
class WeatherApiSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('weather_apis')->updateOrInsert(
            ['code' => 'openmeteo'],
            [
                'name'        => 'Open-Meteo (self-hosted)',
                'base_url'    => env('OPEN_METEO_BASE_URL', 'http://localhost:8888/v1'),
                'auth_type'   => 'none',
                'daily_quota' => null,
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        // API publique Open-Meteo — utilisée pour les modèles que notre
        // instance self-hosted ne sert pas correctement (typiquement
        // UKMO Global qui n'expose pas le vent à 10 m). Quota gratuit
        // ~10 000 req/jour.
        DB::table('weather_apis')->updateOrInsert(
            ['code' => 'openmeteo_public'],
            [
                'name'        => 'Open-Meteo (API publique)',
                'base_url'    => 'https://api.open-meteo.com/v1',
                'auth_type'   => 'none',
                'daily_quota' => 10000,
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        // API « Consensus » — sidecar parapente-consensus-grid (consensus
        // multi-modèles pré-calculé, exposé en compatible Open-Meteo). Sert
        // le modèle `qui_vole_consensus`. Endpoint/port éditables via
        // /admin/apis (champ base_url).
        DB::table('weather_apis')->updateOrInsert(
            ['code' => 'consensus'],
            [
                'name'        => 'Consensus Qui-Vole (sidecar)',
                'base_url'    => env('CONSENSUS_API_URL', 'http://parapente-consensus-grid:8082/v1'),
                'auth_type'   => 'none',
                'daily_quota' => null,
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        // Désactive les éventuelles entrées d'anciennes APIs encore en
        // base (BD existante migrée). Idempotent.
        DB::table('weather_apis')
            ->whereNotIn('code', ['openmeteo', 'openmeteo_public', 'consensus'])
            ->update(['active' => false, 'updated_at' => now()]);
    }
}
