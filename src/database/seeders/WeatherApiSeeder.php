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

        // Désactive les éventuelles entrées d'anciennes APIs encore en
        // base (BD existante migrée). Idempotent.
        DB::table('weather_apis')
            ->whereNotIn('code', ['openmeteo'])
            ->update(['active' => false, 'updated_at' => now()]);
    }
}
