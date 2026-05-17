<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purge rétroactive des entrées /icons-cache/* dans page_views.
 *
 * Le middleware RecordPageView ignorait les routes /icons-cache/ depuis
 * cette release, mais les entrées loggées AVANT polluaient les KPI trafic
 * (chaque icône SpotAir non encore présente sur disque = un hit PHP =
 * une visite enregistrée). Migration data idempotente : on supprime les
 * entrées existantes en une fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('page_views')) {
            return;
        }

        DB::table('page_views')->where('path', 'like', '/icons-cache/%')->delete();
    }

    public function down(): void
    {
        // Pas de rollback possible : les données sont perdues volontairement.
    }
};
