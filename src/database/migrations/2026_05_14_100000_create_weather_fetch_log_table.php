<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des fetches météo (FetchSiteModelJob et FetchBaliseForecastsJob).
 *
 * Permet à l'écran d'admin « Couverture des données » de mesurer :
 *   - la fraîcheur réelle de chaque modèle (dernier fetch côté nous) ;
 *   - le décalage prévu / réalisé entre deux fetches successifs ;
 *   - le volume de lignes upsertées au dernier run (détecte les fetches
 *     cassés silencieusement qui ramènent 0 ligne).
 *
 * Une ligne par (modèle, scope, fin de fetch). Rétention 30 jours
 * (purgée par PurgeOldForecastsJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_fetch_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('weather_model_id')
                  ->constrained('weather_models')
                  ->cascadeOnDelete();

            // 'site'   : FetchSiteModelJob (un site, un modèle)
            // 'balise' : FetchBaliseForecastsJob (batch toutes balises, un modèle)
            $table->enum('scope', ['site', 'balise']);

            // Quand le fetch a été lancé / terminé (côté nous).
            $table->dateTime('fetched_at');

            // Nombre de lignes effectivement upsertées (0 = fetch vide).
            $table->unsignedInteger('rows_upserted')->default(0);

            // Heure du run du modèle côté provider (si exposée par l'API).
            // Open-Meteo n'expose pas systématiquement cette info ; laissé
            // null si non extractible.
            $table->dateTime('provider_run_at')->nullable();

            $table->timestamps();

            $table->index(['weather_model_id', 'fetched_at']);
            $table->index(['scope', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_fetch_log');
    }
};
