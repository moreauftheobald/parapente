<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drapeau d'inclusion d'une balise dans le panel de validation
 * « phase 2.5 » (triple-consensus shadow). Distinct de `reliability_class`
 * (pro/amateur) qui sert un autre objectif (filtrage de la future
 * voting logic Windy/Netatmo).
 *
 * Édition manuelle par l'admin via la fiche `/admin/balises/{id}` —
 * toggle dédié à côté du toggle « actif ».
 *
 * Cf. FF_model_reliability.md (section phase 2.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balises', function (Blueprint $table) {
            $table->boolean('in_consensus_compare_panel')
                  ->default(false)
                  ->after('reliability_class');
        });
    }

    public function down(): void
    {
        Schema::table('balises', function (Blueprint $table) {
            $table->dropColumn('in_consensus_compare_panel');
        });
    }
};
