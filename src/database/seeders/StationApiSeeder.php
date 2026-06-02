<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StationApi;
use Illuminate\Database\Seeder;

class StationApiSeeder extends Seeder
{
    public function run(): void
    {
        $apis = [
            [
                'code'      => 'mf',
                'name'      => 'Météo-France',
                'base_url'  => 'https://portail-api.meteofrance.fr',
                'auth_type' => 'oauth2',
                'config'    => [
                    'description' => 'Stations synoptiques et climatologiques (~600 en France métropolitaine).',
                    'token_url'   => 'https://portail-api.meteofrance.fr/token',
                ],
            ],
            [
                'code'      => 'metar',
                'name'      => 'METAR / NOAA',
                'base_url'  => 'https://aviationweather.gov/api/data/metar',
                'auth_type' => 'none',
                'config'    => ['description' => 'Observations aéronautiques NOAA (~200 aérodromes en France).'],
            ],
            [
                'code'      => 'infoclimat',
                'name'      => 'Infoclimat',
                'base_url'  => 'https://www.infoclimat.fr/opendata/',
                'auth_type' => 'api_key',
                'config'    => ['description' => 'Réseau de stations amateurs (~1000+ stations en France).'],
            ],
        ];

        foreach ($apis as $data) {
            StationApi::updateOrCreate(
                ['code' => $data['code']],
                $data,
            );
        }
    }
}
