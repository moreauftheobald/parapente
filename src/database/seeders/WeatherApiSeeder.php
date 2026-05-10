<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WeatherApiSeeder extends Seeder
{
    public function run(): void
    {
        $apis = [
            [
                'code'        => 'openmeteo',
                'name'        => 'Open-Meteo',
                'base_url'    => 'https://api.open-meteo.com/v1',
                'auth_type'   => 'none',
                'daily_quota' => 10000,
                'active'      => true,
            ],
            [
                'code'        => 'metno',
                'name'        => 'MET Norway',
                'base_url'    => 'https://api.met.no/weatherapi',
                'auth_type'   => 'user_agent',
                'user_agent'  => 'ParapenteFR/1.0 contact@parapentefr.local',
                'daily_quota' => null,
                'active'      => true,
            ],
            [
                'code'        => 'meteofrance',
                'name'        => 'Météo-France',
                'base_url'    => 'https://public-api.meteofrance.fr',
                'auth_type'   => 'oauth2',
                'daily_quota' => 144000, // 100 req/min × 60 × 24
                'active'      => false,  // requiert client_id/secret
            ],
            [
                'code'        => 'dwd',
                'name'        => 'DWD OpenData',
                'base_url'    => 'https://opendata.dwd.de/weather/nwp',
                'auth_type'   => 'none',
                'daily_quota' => null,
                'active'      => false, // implémentation GRIB en PR2/3
            ],
            [
                'code'        => 'ecmwf',
                'name'        => 'ECMWF Open Data',
                'base_url'    => 'https://data.ecmwf.int/forecasts',
                'auth_type'   => 'none',
                'daily_quota' => null,
                'active'      => false, // implémentation GRIB en PR2/3
            ],
        ];

        foreach ($apis as $api) {
            DB::table('weather_apis')->updateOrInsert(
                ['code' => $api['code']],
                array_merge($api, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
