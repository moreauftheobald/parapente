<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renomme le module « Carte météo » (key=map) en « Carte de volabilité »
 * et ajoute un nouveau module « Carte météo » (key=weather-map) qui pointe
 * vers la vue d'overlays météo (consensus-grid sidecar).
 *
 * Visible admin-only le temps de stabiliser l'intégration du sidecar.
 *
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        DB::table('modules')->where('key', 'map')->update([
            'label'      => 'Carte de volabilité',
            'updated_at' => now(),
        ]);

        DB::table('modules')->updateOrInsert(
            ['key' => 'weather-map'],
            [
                'label'                 => 'Carte météo',
                'icon'                  => 'fa-solid fa-cloud-sun',
                'route_name'            => 'weather-map.index',
                'is_active'             => true,
                'access_level'          => 'admin',
                'requires_registration' => true,
                'sort_order'            => 25,
                'updated_at'            => now(),
                'created_at'            => now(),
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        DB::table('modules')->where('key', 'map')->update([
            'label'      => 'Carte météo',
            'updated_at' => now(),
        ]);

        DB::table('modules')->where('key', 'weather-map')->delete();
    }
};
