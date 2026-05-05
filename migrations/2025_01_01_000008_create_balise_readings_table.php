<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balise_readings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('balise_id')
                  ->constrained('balises')
                  ->cascadeOnDelete();

            // Horodatage du relevé (précision à la minute)
            $table->dateTime('read_at');

            // ── Vent ─────────────────────────────────────────────
            $table->unsignedSmallInteger('wind_direction')->nullable();   // degrés 0-359
            $table->decimal('wind_speed_avg', 5, 1)->nullable();          // km/h
            $table->decimal('wind_speed_min', 5, 1)->nullable();          // km/h
            $table->decimal('wind_speed_max', 5, 1)->nullable();          // km/h (rafales)

            // ── Optionnel selon la balise ─────────────────────────
            $table->decimal('temperature', 4, 1)->nullable();             // °C
            $table->unsignedTinyInteger('humidity')->nullable();           // %

            // Pas de updated_at : les relevés sont immuables
            $table->timestamp('created_at')->useCurrent();

            // Index pour jointure avec forecasts (comparaison heure par heure)
            $table->index(['balise_id', 'read_at']);

            // Index pour purge des vieilles données
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balise_readings');
    }
};
