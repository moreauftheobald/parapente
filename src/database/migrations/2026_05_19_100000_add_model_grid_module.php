<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le module « Carte des modèles » dans la barre de menu (table
 * `modules`).
 *
 * Visualisation didactique de la grille de chaque modèle météo avec
 * coloration par fiabilité issue de `model_reliability`. Accessible
 * uniquement aux admins le temps que la couverture du panel de balises
 * soit suffisante.
 *
 * Idempotent — utilise un upsert sur la clé unique `key`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        DB::table('modules')->updateOrInsert(
            ['key' => 'model-grid'],
            [
                'label'                 => 'Carte des modèles',
                'icon'                  => 'fa-solid fa-table-cells',
                'route_name'            => 'model-grid.index',
                'is_active'             => true,
                'access_level'          => 'admin',
                'requires_registration' => true,
                'sort_order'            => 50,
                'updated_at'            => now(),
                'created_at'            => now(),
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('key', 'model-grid')->delete();
        }
    }
};
