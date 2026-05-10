<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des sources API exploitées pour récupérer les prévisions météo.
 *
 * Une `weather_api` représente un service distant (Open-Meteo, MET Norway,
 * Météo-France, DWD OpenData, ECMWF Open Data…). Chaque `weather_models`
 * pointe vers UNE de ces APIs pour son fetch (politique single-shot,
 * configurable dans l'admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_apis', function (Blueprint $table) {
            $table->id();

            // Slug technique (openmeteo, metno, meteofrance, dwd, ecmwf)
            $table->string('code', 32)->unique();

            // Nom affiché
            $table->string('name');

            // URL de base
            $table->string('base_url');

            // Mode d'authentification : none|user_agent|api_key|oauth2
            $table->string('auth_type', 16)->default('none');

            // Identifiants (encrypted via Laravel cast)
            $table->text('api_key')->nullable();
            $table->string('user_agent')->nullable();

            // OAuth2 (Météo-France)
            $table->text('oauth_client_id')->nullable();
            $table->text('oauth_client_secret')->nullable();
            $table->text('oauth_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();

            // Quota déclaratif (req/jour) — pour affichage admin uniquement
            $table->unsignedInteger('daily_quota')->nullable();
            $table->unsignedInteger('requests_today')->default(0);
            $table->date('requests_counter_date')->nullable();

            // Diagnostic du dernier appel
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_success_at')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_apis');
    }
};
