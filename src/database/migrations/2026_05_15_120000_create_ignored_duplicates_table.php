<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mémorise les paires de doublons explicitement ignorées par l'admin
 * dans l'écran « Qualité des données » (/admin/data-quality), pour
 * qu'elles ne réapparaissent pas à chaque visite.
 *
 * Polymorphe : entity_type = 'site' | 'balise'. Canonisation : on
 * stocke toujours entity_a_id < entity_b_id pour qu'une paire (A,B) et
 * (B,A) ne soit jamais doublonnée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ignored_duplicates', function (Blueprint $table) {
            $table->id();

            $table->string('entity_type', 20);          // 'site' | 'balise'
            $table->unsignedBigInteger('entity_a_id');  // toujours < entity_b_id
            $table->unsignedBigInteger('entity_b_id');

            $table->foreignId('ignored_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            $table->unique(['entity_type', 'entity_a_id', 'entity_b_id'], 'ign_dup_pair_unique');
            $table->index(['entity_type', 'entity_a_id'], 'ign_dup_lookup_a');
            $table->index(['entity_type', 'entity_b_id'], 'ign_dup_lookup_b');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ignored_duplicates');
    }
};
