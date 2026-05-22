<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purge rétroactive des entrées /carte-meteo/overlay/* dans page_views.
 *
 * Avant la mise en place du `proxy_cache` nginx (qui intercepte ces URLs
 * avant qu'elles atteignent PHP), chaque PNG d'overlay passait par le
 * `MapController`/`WeatherMapController` et était comptabilisé par le
 * middleware `RecordPageView`. Avec 121 steps × 11 variables × 2 layers
 * cela poluait fortement les KPI trafic dès qu'un admin ouvrait la carte.
 * Migration data idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('page_views')) {
            return;
        }

        DB::table('page_views')->where('path', 'like', '/carte-meteo/overlay/%')->delete();
    }

    public function down(): void
    {
        // Pas de rollback : données supprimées volontairement.
    }
};
