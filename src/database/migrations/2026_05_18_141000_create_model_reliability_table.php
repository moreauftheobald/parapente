<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiabilité dynamique des modèles météo, calculée par variable et par
 * horizon de prévision. Une ligne par tuple
 * (modèle × balise × bucket × variable).
 *
 * Alimentée par `ComputeModelReliabilityJob` (phase 2). Consommée par :
 *  - l'écran `/admin/reliability/models` (surveillance) ;
 *  - le consensus C de la phase 2.5 (pondération par `weight_factor`) ;
 *  - plus tard, l'intégration dans `ScoringService` (phase 4).
 *
 * Cf. FF_model_reliability.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_reliability', function (Blueprint $table) {
            $table->id();

            $table->foreignId('weather_model_id')
                  ->constrained('weather_models')
                  ->cascadeOnDelete();

            $table->foreignId('balise_id')
                  ->constrained('balises')
                  ->cascadeOnDelete();

            $table->enum('horizon_bucket', ['nowcast', 'same_day', 'j_plus_1', 'j_plus_2']);

            $table->enum('variable', ['wind_speed_avg', 'wind_speed_max', 'wind_direction']);

            // Métriques d'erreur agrégées sur la fenêtre glissante
            // (window_days, défaut 7). Calculées par
            // `ComputeModelReliabilityJob`. Toutes nullables tant que
            // `samples_n` < seuil de confiance.
            $table->decimal('mae',         6, 2)->nullable();
            $table->decimal('rmse',        6, 2)->nullable();
            $table->decimal('bias_signed', 6, 2)->nullable();

            // Multiplicateur appliqué à la voting logic. 1.00 par défaut
            // (cold start : neutre tant que la fiabilité n'est pas
            // calculée). Bornes effectives appliquées au calcul :
            // `reliability.factor_min` / `reliability.factor_max`.
            $table->decimal('weight_factor', 4, 2)->default(1.00);

            // Nombre de paires (prévu, observé) ayant servi au calcul.
            // En dessous de `reliability.min_samples`, on retombe sur
            // weight_factor = 1.00 (cf. FF, garde-fou cold start).
            $table->unsignedInteger('samples_n')->default(0);

            $table->dateTime('computed_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['weather_model_id', 'balise_id', 'horizon_bucket', 'variable'],
                'model_reliability_unique_idx'
            );

            // Lecture côté admin (pivot par balise × bucket × variable)
            $table->index(['balise_id', 'horizon_bucket', 'variable'], 'model_reliability_admin_idx');

            // Jointures futures côté ScoringService (phase 4)
            $table->index(['weather_model_id', 'horizon_bucket', 'variable'], 'model_reliability_scoring_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_reliability');
    }
};
