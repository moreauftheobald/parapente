<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            [
                'key'                   => 'home',
                'label'                 => 'Accueil',
                'icon'                  => 'fa-solid fa-house',
                'route_name'            => 'home',
                'access_level'          => 'guest',
                'requires_registration' => false,
                'sort_order'            => 10,
            ],
            [
                'key'                   => 'map',
                'label'                 => 'Carte de volabilité',
                'icon'                  => 'fa-solid fa-map-location-dot',
                'route_name'            => 'map',
                'access_level'          => 'guest',
                'requires_registration' => false,
                'sort_order'            => 20,
            ],
            [
                'key'                   => 'weather-map',
                'label'                 => 'Carte météo',
                'icon'                  => 'fa-solid fa-cloud-sun',
                'route_name'            => 'weather-map.index',
                'access_level'          => 'admin',
                'requires_registration' => true,
                'sort_order'            => 25,
            ],
            [
                'key'                   => 'logbook',
                'label'                 => 'Journal de vol',
                'icon'                  => 'fa-solid fa-book',
                'route_name'            => null, // module 2 — pas encore implémenté
                'access_level'          => 'user',
                'requires_registration' => true,
                'sort_order'            => 30,
            ],
            [
                'key'                   => 'wiki',
                'label'                 => 'Aide',
                'icon'                  => 'fa-solid fa-circle-question',
                'route_name'            => 'wiki.index',
                'access_level'          => 'guest',
                'requires_registration' => false,
                'sort_order'            => 40,
            ],
            [
                'key'                   => 'model-grid',
                'label'                 => 'Carte des modèles',
                'icon'                  => 'fa-solid fa-table-cells',
                'route_name'            => 'model-grid.index',
                'access_level'          => 'admin',
                'requires_registration' => true,
                'sort_order'            => 50,
            ],
            [
                'key'                   => 'admin',
                'label'                 => 'Administration',
                'icon'                  => 'fa-solid fa-gauge-high',
                'route_name'            => 'admin.dashboard',
                'access_level'          => 'admin',
                'requires_registration' => true,
                'sort_order'            => 90,
            ],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(['key' => $module['key']], $module + ['is_active' => true]);
        }
    }
}
