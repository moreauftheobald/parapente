<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pré-requis bloquant pour l'intégration Windy / Netatmo / Davis :
 * permettre au scoring de filtrer les balises *amateur* (PWS de
 * jardin) qu'on ne peut pas comparer à un Holfuy ou un anémomètre
 * d'aérodrome. Sans ce flag, ouvrir Windy en grand reviendrait à
 * polluer la voting logic avec des stations à ~2 m du sol abritées
 * par un mur — pas représentatif du décollage.
 *
 * - `reliability_class` :
 *     'pro'     → station professionnelle (PiouPiou, METAR, Holfuy
 *                 sur décollage, anémomètre aérodrome).
 *     'amateur' → PWS particulier (Netatmo, Davis perso non
 *                 normalisé).
 *     null      → pas encore tagguée. La voting logic doit traiter
 *                 null comme « ne pas inclure » par sécurité.
 * - `height_agl_m` : hauteur de l'anémomètre au-dessus du sol (mètres).
 *     Aide à évaluer la fiabilité (10 m mât aérodrome vs 2 m jardin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balises', function (Blueprint $table) {
            $table->enum('reliability_class', ['pro', 'amateur'])
                  ->nullable()
                  ->after('source');

            $table->unsignedSmallInteger('height_agl_m')
                  ->nullable()
                  ->after('altitude_m');

            $table->index(['active', 'reliability_class'], 'balises_active_reliability_idx');
        });

        // Backfill des sources existantes : PiouPiou et METAR sont
        // toutes deux considérées 'pro' par construction (réseau dédié,
        // matériel calibré, hauteur > 2 m).
        DB::table('balises')->where('source', 'pioupiou')->update(['reliability_class' => 'pro']);
        DB::table('balises')->where('source', 'metar')->update(['reliability_class' => 'pro']);
    }

    public function down(): void
    {
        Schema::table('balises', function (Blueprint $table) {
            $table->dropIndex('balises_active_reliability_idx');
            $table->dropColumn(['reliability_class', 'height_agl_m']);
        });
    }
};
