<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la traçabilité d'origine sur les sites de vol :
 *   - source       : 'manual' (défaut) | 'paraglidingearth' | future autres
 *   - external_id  : id du site dans le système source (nullable)
 *
 * Sert à :
 *   1. Distinguer les sites seedés à la main des sites importés via API
 *      (notamment pour ne pas écraser les ajustements manuels).
 *   2. Permettre la sync différentielle ultérieure (firstOrNew sur la
 *      clé (source, external_id) au lieu du slug).
 *
 * On retire aussi la contrainte UNIQUE sur slug : avec l'import PGE,
 * deux sites peuvent avoir le même nom (et donc le même slug auto-
 * généré). On suffixera les slugs des sites importés avec l'external_id
 * pour garder une lisibilité, mais on ne veut plus risquer un crash
 * d'INSERT sur une collision de slug. L'unicité métier passe sur
 * (source, external_id) à la place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('source', 30)->default('manual')->after('region');
            $table->string('external_id', 50)->nullable()->after('source');
        });

        // Marque tous les sites existants comme manuels (par sécurité,
        // même si default='manual' couvre déjà le cas)
        DB::table('sites')->update(['source' => 'manual']);

        // Drop l'unique sur slug + recrée un index simple pour les
        // recherches de site par slug (URL routing futur).
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->index('slug');
        });

        // Unicité métier : un site source/external_id n'existe qu'une fois
        Schema::table('sites', function (Blueprint $table) {
            $table->unique(['source', 'external_id'], 'sites_source_external_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropUnique('sites_source_external_id_unique');
            $table->dropIndex(['slug']);
            $table->unique('slug');
            $table->dropColumn(['source', 'external_id']);
        });
    }
};
