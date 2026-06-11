<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index couvrants pour l'écran « Data / couverture » (et plus largement
 * toute agrégation temporelle sur les grosses tables météo).
 *
 * Les index simples sur la colonne temporelle ne suffisent pas : les
 * agrégations (COUNT DISTINCT par modèle / unité) doivent alors relire
 * chaque LIGNE (millions de lookups sur forecast_archive_* à cause de
 * la dimension bucket) → timeout. Avec la colonne d'unité (et de modèle
 * pour les archives) DANS l'index, les requêtes deviennent « index-only »
 * et ne touchent plus les lignes.
 *
 * ⚠️ Déploiement : la création sur forecast_archive_stations (~15 M
 * lignes) peut prendre plusieurs minutes — lancer la migration hors pic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_archive_balises', function (Blueprint $table) {
            $table->index(['target_at', 'weather_model_id', 'balise_id'], 'fab_target_model_balise_idx');
        });

        Schema::table('forecast_archive_stations', function (Blueprint $table) {
            $table->index(['target_at', 'weather_model_id', 'weather_station_id'], 'fas_target_model_station_idx');
        });

        Schema::table('balise_readings', function (Blueprint $table) {
            $table->index(['read_at', 'balise_id'], 'br_read_balise_idx');
        });

        Schema::table('weather_station_observations', function (Blueprint $table) {
            $table->index(['observed_at', 'weather_station_id'], 'wso_observed_station_idx');
        });

        Schema::table('balise_readings_hourly', function (Blueprint $table) {
            $table->index(['hour_at', 'balise_id'], 'brh_hour_balise_idx');
        });

        Schema::table('weather_station_observations_hourly', function (Blueprint $table) {
            $table->index(['hour_at', 'weather_station_id'], 'wsoh_hour_station_idx');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_archive_balises', fn (Blueprint $t) => $t->dropIndex('fab_target_model_balise_idx'));
        Schema::table('forecast_archive_stations', fn (Blueprint $t) => $t->dropIndex('fas_target_model_station_idx'));
        Schema::table('balise_readings', fn (Blueprint $t) => $t->dropIndex('br_read_balise_idx'));
        Schema::table('weather_station_observations', fn (Blueprint $t) => $t->dropIndex('wso_observed_station_idx'));
        Schema::table('balise_readings_hourly', fn (Blueprint $t) => $t->dropIndex('brh_hour_balise_idx'));
        Schema::table('weather_station_observations_hourly', fn (Blueprint $t) => $t->dropIndex('wsoh_hour_station_idx'));
    }
};
