<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sites « masqués » par un utilisateur sur la carte de volabilité.
 *
 * Modèle d'exclusion pure : la présence d'une ligne (user_id, site_id)
 * signifie « cet utilisateur ne veut PAS voir ce site sur la carte ».
 * Absence de ligne = site affiché (règle par défaut). Pas de backfill,
 * pas de ligne par site et par utilisateur. Cf. FF_site_blacklist.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_hidden_sites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('site_id')
                  ->constrained('sites')
                  ->cascadeOnDelete();

            $table->timestamps();

            // Un site n'est masqué qu'une fois par utilisateur.
            $table->unique(['user_id', 'site_id']);
            // Lecture courante : WHERE user_id = ? (liste des masqués du user).
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_hidden_sites');
    }
};
