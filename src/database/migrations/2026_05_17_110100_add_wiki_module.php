<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le module « Aide » dans la barre de menu (table `modules`).
 *
 * Idempotent — utilise un upsert sur la clé unique `key`. Migration data
 * indépendante du seeder pour qu'un simple `php artisan migrate` en prod
 * fasse apparaître le module sans qu'on ait à relancer ModuleSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        DB::table('modules')->updateOrInsert(
            ['key' => 'wiki'],
            [
                'label'                 => 'Aide',
                'icon'                  => 'fa-solid fa-circle-question',
                'route_name'            => 'wiki.index',
                'is_active'             => true,
                'access_level'          => 'guest',
                'requires_registration' => false,
                'sort_order'            => 40,
                'updated_at'            => now(),
                'created_at'            => now(),
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('key', 'wiki')->delete();
        }
    }
};
