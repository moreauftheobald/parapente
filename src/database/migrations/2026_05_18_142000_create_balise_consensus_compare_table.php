<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des comparaisons de consensus en shadow mode (phase 2.5).
 *
 * Pour chaque tuple (balise du panel, créneau, horizon, variable), on
 * stocke trois consensus calculés en parallèle :
 *  - A : algo legacy (inverse-carré linéaire, EPSILON = 0.001) ;
 *  - B : amélioré sans fiabilité (EPSILON revu, médiane pondérée, MAD) ;
 *  - C : amélioré avec fiabilité (idem B + weight_factor par modèle).
 *
 * Et l'observation correspondante (lue dans `balise_readings_hourly`)
 * quand elle est disponible (créneaux passés uniquement).
 *
 * Alimente l'écran `/admin/reliability/compare` (J / J+1 / J+2 contre
 * vérité-terrain).
 *
 * Cf. FF_model_reliability.md (section phase 2.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balise_consensus_compare', function (Blueprint $table) {
            $table->id();

            $table->foreignId('balise_id')
                  ->constrained('balises')
                  ->cascadeOnDelete();

            // Heure pile du créneau prévu (ex: 2026-05-18 14:00:00)
            $table->dateTime('target_at');

            $table->enum('horizon_bucket', ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2']);

            $table->enum('variable', ['wind_speed_avg', 'wind_speed_max', 'wind_direction']);

            // Les 3 consensus calculés. Direction stockée en degrés
            // (0-359, valeurs entières), vitesses en km/h (décimales).
            // Le decimal(6,2) couvre les deux usages avec marge.
            $table->decimal('consensus_a', 6, 2)->nullable();
            $table->decimal('consensus_b', 6, 2)->nullable();
            $table->decimal('consensus_c', 6, 2)->nullable();

            // Vérité-terrain : moyenne de l'heure dans balise_readings_hourly.
            // Nullable car les créneaux futurs n'en ont pas, et certaines
            // heures passées peuvent manquer (panne balise).
            $table->decimal('observation', 6, 2)->nullable();

            // Nombre de readings (1 h pile) ayant alimenté l'observation.
            $table->unsignedSmallInteger('observation_count')->nullable();

            // Nombre de modèles ayant contribué au consensus.
            $table->unsignedSmallInteger('models_count')->default(0);

            // MAD calculée du créneau (avant filtrage outliers).
            // Utile pour debug : si MAD plancher est touché tout le temps,
            // signe que les modèles convergent vraiment ou que le plancher
            // est mal réglé.
            $table->decimal('mad_value', 6, 2)->nullable();

            $table->dateTime('computed_at');

            $table->timestamps();

            $table->unique(
                ['balise_id', 'target_at', 'horizon_bucket', 'variable'],
                'balise_consensus_compare_unique_idx'
            );

            // Lecture côté écran admin (filtrage par balise × variable,
            // tri par target_at)
            $table->index(['balise_id', 'variable', 'target_at'], 'balise_consensus_compare_admin_idx');

            // Purge (rétention 14 jours, alignée sur forecast_archive_balises)
            $table->index('target_at', 'balise_consensus_compare_purge_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balise_consensus_compare');
    }
};
