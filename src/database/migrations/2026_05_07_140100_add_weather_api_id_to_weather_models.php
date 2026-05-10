<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lie chaque modèle météo à l'API qui doit le servir.
 *
 * Politique single-shot : un seul provider par modèle, choisi dans
 * l'admin. Si le fetch échoue, on log dans weather_apis.last_error et
 * on attend le prochain cycle (refresh_frequency_minutes).
 *
 * `last_fetch_at` permet au scheduler de savoir si un (site, model) est
 * éligible à un nouveau fetch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->foreignId('weather_api_id')
                  ->nullable()
                  ->after('provider')
                  ->constrained('weather_apis')
                  ->nullOnDelete();

            $table->timestamp('last_fetch_at')
                  ->nullable()
                  ->after('refresh_frequency_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weather_api_id');
            $table->dropColumn('last_fetch_at');
        });
    }
};
