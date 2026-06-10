<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat horaire des observations des stations météo, pendant de
 * `balise_readings_hourly` pour les stations.
 *
 * Les observations brutes arrivent à des cadences hétérogènes (6 min
 * Météo-France, 30 min METAR, 60 min Infoclimat) ; cet agrégat les
 * ramène sur l'heure pile pour permettre la jointure directe avec les
 * prévisions archivées dans `forecast_archive_stations` (calcul de
 * fiabilité par modèle × bucket × variable).
 *
 * Rétention 30 jours (fenêtre de fiabilité), purgée par
 * PurgeOldForecastsJob — les observations brutes ne sont conservées
 * que 7 jours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_station_observations_hourly', function (Blueprint $table) {
            $table->id();

            $table->foreignId('weather_station_id')
                  ->constrained('weather_stations')
                  ->cascadeOnDelete();

            $table->dateTime('hour_at');

            // Vent : direction = moyenne circulaire, moyenne = AVG, rafale = MAX
            $table->unsignedSmallInteger('wind_direction')->nullable();
            $table->decimal('wind_speed_avg', 5, 1)->nullable();
            $table->decimal('wind_speed_max', 5, 1)->nullable();

            // Température & humidité (AVG)
            $table->decimal('temperature', 5, 1)->nullable();
            $table->decimal('dew_point', 5, 1)->nullable();
            $table->unsignedTinyInteger('humidity')->nullable();

            // Précipitations : SOMME des relevés de l'heure (les réseaux
            // infrahoraires comme MF publient des tranches de 6 min).
            $table->decimal('precipitation_mm', 6, 2)->nullable();

            // Pression (AVG) et couverture nuageuse (AVG)
            $table->decimal('pressure_hpa', 6, 1)->nullable();
            $table->unsignedTinyInteger('cloud_cover_pct')->nullable();

            $table->unsignedSmallInteger('obs_count')->default(0);

            $table->timestamps();

            $table->unique(['weather_station_id', 'hour_at'], 'ws_obs_hourly_unique');
            $table->index('hour_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_station_observations_hourly');
    }
};
