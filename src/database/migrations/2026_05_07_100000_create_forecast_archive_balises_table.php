<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive des prévisions ramenées aux coordonnées d'une balise, pour
 * comparaison ultérieure avec les observations réelles (calcul de
 * fiabilité par modèle / horizon / variable / balise).
 *
 * Payload réduit : on ne stocke que ce qui est mesurable par les
 * balises (vent direction + vitesse min/avg/max + température). On
 * n'archive PAS les nuages, plafond, humidité, précipitations qui ne
 * sont pas vérifiables depuis une balise standard.
 *
 * Convention horizon_bucket :
 *   - nowcast    (0-6 h)
 *   - same_day   (6-24 h)
 *   - j_plus_1   (24-48 h)
 *   - j_plus_2   (48-72 h)
 *
 * Au-delà de 72 h (J+2), on n'archive PAS : la voting logic ne
 * pondère pas dynamiquement à long terme (poids statiques utilisés).
 *
 * Cette table est créée maintenant en prévision de la phase 1
 * (intégration des balises). Tant qu'aucune balise n'est ingérée,
 * elle reste vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_archive_balises', function (Blueprint $table) {
            $table->id();

            $table->foreignId('balise_id')
                  ->constrained('balises')
                  ->cascadeOnDelete();

            $table->foreignId('weather_model_id')
                  ->constrained('weather_models')
                  ->cascadeOnDelete();

            // Créneau prévu (heure ronde, ex: 2026-05-09 14:00:00)
            $table->dateTime('target_at');

            // Quand on a fetché cette prévision
            $table->dateTime('fetched_at');

            // Bucket d'horizon (calculé à l'archivage)
            $table->enum('horizon_bucket', ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2']);

            // Payload réduit (variables mesurables par les balises)
            $table->unsignedSmallInteger('wind_direction');       // ° 0-359
            $table->decimal('wind_speed_avg', 5, 1);              // km/h
            $table->decimal('wind_speed_min', 5, 1);              // km/h
            $table->decimal('wind_speed_max', 5, 1);              // km/h (rafales)
            $table->decimal('temperature',    4, 1);              // °C

            $table->timestamps();

            // Une ligne par tuple (balise, modèle, créneau, bucket).
            // Si on re-fetch dans la même heure pour le même slot/bucket,
            // on update la ligne (l'observation la plus fraîche prime).
            $table->unique(
                ['balise_id', 'weather_model_id', 'target_at', 'horizon_bucket'],
                'forecast_archive_balises_unique'
            );

            // Index pour la purge périodique (slots > 30 jours)
            $table->index('target_at');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_archive_balises');
    }
};
