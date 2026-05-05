<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_models', function (Blueprint $table) {
            $table->id();

            // Identifiant Open-Meteo (ex: "meteofrance_arome_france")
            $table->string('code')->unique();

            // Nom affiché
            $table->string('name');

            // Organisme fournisseur
            $table->string('provider');

            // Résolution spatiale en km
            $table->decimal('resolution_km', 5, 1);

            // Horizon de prévision max en heures
            $table->unsignedSmallInteger('max_horizon_h');

            // Poids dans la voting logic selon l'échéance
            // Court terme J+1/J+2 (<=48h)
            $table->decimal('weight_short', 3, 2)->default(1.00);
            // Moyen terme J+3/J+5 (48-120h)
            $table->decimal('weight_medium', 3, 2)->default(1.00);

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_models');
    }
};
