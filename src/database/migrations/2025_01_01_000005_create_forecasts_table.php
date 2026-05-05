<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecasts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                  ->constrained('sites')
                  ->cascadeOnDelete();

            $table->foreignId('weather_model_id')
                  ->constrained('weather_models')
                  ->cascadeOnDelete();

            // Créneau horaire prévu (heure ronde : 2025-06-15 14:00:00)
            $table->dateTime('forecast_at');

            // Moment où on a récupéré la donnée
            $table->dateTime('fetched_at');

            // ── Vent ─────────────────────────────────────────────
            $table->unsignedSmallInteger('wind_direction');       // degrés 0-359
            $table->decimal('wind_speed_avg', 5, 1);              // km/h
            $table->decimal('wind_speed_min', 5, 1);              // km/h
            $table->decimal('wind_speed_max', 5, 1);              // km/h (rafales)

            // ── Précipitations ───────────────────────────────────
            $table->decimal('precipitation', 5, 1);               // mm/h

            // ── Nuages ───────────────────────────────────────────
            $table->unsignedTinyInteger('cloud_cover_low');       // % basse couche
            $table->unsignedTinyInteger('cloud_cover_mid');       // % couche moyenne
            $table->unsignedTinyInteger('cloud_cover_high');      // % haute couche
            $table->unsignedSmallInteger('cloud_base_m')->nullable(); // plafond en m

            // ── Autres ───────────────────────────────────────────
            $table->decimal('temperature', 4, 1);                 // °C
            $table->unsignedTinyInteger('humidity');               // %

            $table->timestamps();

            // Index composite principal (requête la plus fréquente)
            $table->unique(['site_id', 'weather_model_id', 'forecast_at'], 'forecasts_unique');

            // Index pour purge des anciennes données
            $table->index('forecast_at');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecasts');
    }
};
