<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                  ->constrained('sites')
                  ->cascadeOnDelete();

            // Créneau horaire évalué
            $table->dateTime('forecast_at');

            // Moment du calcul
            $table->dateTime('computed_at');

            // ── Résultat final ───────────────────────────────────
            $table->enum('status', ['green', 'orange', 'red', 'unknown'])
                  ->default('unknown');

            // Pourcentage de confiance global (0-100)
            $table->unsignedTinyInteger('confidence_pct')->default(0);

            // ── Valeurs consensus (moyenne pondérée inverse carré) ──
            $table->unsignedSmallInteger('wind_dir_consensus')->nullable();   // degrés
            $table->decimal('wind_speed_consensus', 5, 1)->nullable();        // km/h
            $table->decimal('precip_consensus', 5, 1)->nullable();            // mm/h

            // ── Statistiques de vote ─────────────────────────────
            $table->unsignedTinyInteger('models_count');      // modèles ayant fourni une donnée
            $table->unsignedTinyInteger('models_converging'); // modèles convergeant sur la décision

            // ── Détail par variable (JSON) ────────────────────────
            // Stocke la convergence variable par variable pour l'affichage détaillé
            // Structure : { wind_dir: { convergence: 0.87, values: [...] }, ... }
            $table->json('detail')->nullable();

            $table->timestamps();

            // Un score unique par site et par créneau
            $table->unique(['site_id', 'forecast_at'], 'site_scores_unique');

            // Index pour affichage carte
            $table->index(['site_id', 'forecast_at', 'status']);
            $table->index('forecast_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_scores');
    }
};
