<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive des prévisions ramenées aux coordonnées des stations météo,
 * pour comparaison ultérieure avec les observations réelles (calcul de
 * fiabilité par modèle / horizon / variable / station).
 *
 * Payload étendu par rapport à forecast_archive_balises : les stations
 * mesurent davantage de variables (humidité, précipitations, pression,
 * point de rosée, couverture nuageuse) — on archive tout ce qui est
 * comparable.
 *
 * Convention horizon_bucket : identique aux balises.
 *   - nowcast    (0-6 h)
 *   - same_day   (6-24 h)
 *   - j_plus_1   (24-48 h)
 *   - j_plus_2   (48-72 h)
 *
 * Rétention 30 jours, purgée par PurgeOldForecastsJob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_archive_stations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('weather_station_id')
                  ->constrained('weather_stations')
                  ->cascadeOnDelete();

            $table->foreignId('weather_model_id')
                  ->constrained('weather_models')
                  ->cascadeOnDelete();

            $table->dateTime('target_at');
            $table->dateTime('fetched_at');

            $table->enum('horizon_bucket', ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2']);

            // Vent
            $table->unsignedSmallInteger('wind_direction')->nullable();
            $table->decimal('wind_speed_avg', 5, 1)->nullable();
            $table->decimal('wind_speed_max', 5, 1)->nullable();

            // Température & humidité
            $table->decimal('temperature', 5, 1)->nullable();
            $table->decimal('dew_point', 5, 1)->nullable();
            $table->unsignedTinyInteger('humidity')->nullable();

            // Précipitations
            $table->decimal('precipitation', 6, 2)->nullable();

            // Pression (réduite au niveau de la mer)
            $table->decimal('pressure_hpa', 6, 1)->nullable();

            // Couverture nuageuse
            $table->unsignedTinyInteger('cloud_cover')->nullable();

            $table->timestamps();

            $table->unique(
                ['weather_station_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                'forecast_archive_stations_unique'
            );

            $table->index('target_at');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_archive_stations');
    }
};
