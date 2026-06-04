<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute la valeur 'station' à l'ENUM scope de weather_fetch_log.
 *
 * FetchStationForecastsJob écrit scope='station' pour tracer
 * l'archivage des prévisions aux coordonnées des stations météo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE weather_fetch_log MODIFY COLUMN scope ENUM('site', 'balise', 'station') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE weather_fetch_log MODIFY COLUMN scope ENUM('site', 'balise') NOT NULL");
    }
};
