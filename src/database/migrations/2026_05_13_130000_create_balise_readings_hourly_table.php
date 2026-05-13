<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat horaire des lectures balises, aligné sur la même granularité
 * que `forecast_archive_balises` (1 ligne par heure pile) pour permettre
 * une comparaison directe modèles ↔ balises sur la fenêtre J-6 → J.
 *
 * - direction du vent : moyenne circulaire sur l'heure
 * - vitesse moyenne   : moyenne arithmétique des `wind_speed_avg`
 * - rafale            : MAX des `wind_speed_max` de l'heure
 * - température       : moyenne arithmétique
 *
 * Rétention : 7 jours (purgé par PurgeOldForecastsJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balise_readings_hourly', function (Blueprint $table) {
            $table->id();

            $table->foreignId('balise_id')
                  ->constrained('balises')
                  ->cascadeOnDelete();

            // Heure pile (ex: 2026-05-13 14:00:00) — borne basse de l'agrégat
            $table->dateTime('hour_at');

            $table->unsignedSmallInteger('wind_direction')->nullable();  // ° 0-359 (moy. circulaire)
            $table->decimal('wind_speed_avg', 5, 1)->nullable();         // km/h
            $table->decimal('wind_speed_max', 5, 1)->nullable();         // km/h (rafale = max des max)
            $table->decimal('temperature',    4, 1)->nullable();         // °C

            // Nombre de lectures sources agrégées (info qualité)
            $table->unsignedSmallInteger('readings_count')->default(0);

            $table->timestamps();

            $table->unique(['balise_id', 'hour_at']);
            $table->index('hour_at'); // purge
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balise_readings_hourly');
    }
};
