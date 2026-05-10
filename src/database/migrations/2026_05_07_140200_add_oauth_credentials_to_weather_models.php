<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials OAuth2 par modèle.
 *
 * Le portail Météo-France v2 fonctionne en "per-dataset subscription" :
 * chaque modèle (AROME 1.3km, ARPEGE 0.1°…) reçoit sa propre paire
 * client_id/client_secret. Les colonnes oauth_* sur `weather_apis`
 * sont conservées pour les éventuelles APIs OAuth2 globales mais ne
 * sont pas exploitées pour Météo-France.
 *
 * Les valeurs sont chiffrées via Eloquent cast (cf. WeatherModel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->text('oauth_client_id')->nullable()->after('weather_api_id');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');
            $table->text('oauth_token')->nullable()->after('oauth_client_secret');
            $table->timestamp('oauth_expires_at')->nullable()->after('oauth_token');
            // Endpoint spécifique à la souscription (chaque API MF a son URL).
            $table->string('endpoint_url')->nullable()->after('oauth_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->dropColumn([
                'oauth_client_id',
                'oauth_client_secret',
                'oauth_token',
                'oauth_expires_at',
                'endpoint_url',
            ]);
        });
    }
};
