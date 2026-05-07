<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rend `sites.altitude_m` nullable.
 *
 * À l'origine NOT NULL (les sites seedés Grand Est avaient tous une
 * altitude connue). L'import PGE peut comporter des sites pour lesquels
 * l'altitude n'est pas renseignée (PGE place "-1" dans ce cas) → on
 * accepte NULL plutôt qu'une valeur factice (0 = niveau de la mer
 * serait trompeur).
 *
 * Type conservé : UNSIGNED SMALLINT (max 65535m, suffisant pour tous
 * les sites parapente connus, Everest 8849m inclus).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sites MODIFY altitude_m SMALLINT UNSIGNED NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE sites MODIFY altitude_m SMALLINT UNSIGNED NOT NULL");
    }
};
