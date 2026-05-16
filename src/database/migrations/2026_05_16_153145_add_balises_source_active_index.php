<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index composite `balises (source, active)`.
 *
 * Justification : les trois jobs de polling (`Fetch{PiouPiou,Metar,Windy}
 * ReadingsJob`) et le builder du bundle balises filtrent quasi exclusivement
 * sur ce couple de colonnes (`Balise::where('source', X)->where('active', true)`).
 * À l'échelle nationale (objectif >500 balises), un index dédié évite à
 * MariaDB de combiner deux index simples ou de scanner la table entière.
 *
 * Idempotence : `CREATE INDEX IF NOT EXISTS` (MariaDB 10.0+). On évite
 * ainsi un crash en cas de relancement de la migration ou d'application
 * sur un environnement où l'index aurait déjà été créé à la main.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'balises_source_active_idx';

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS ' . self::INDEX_NAME . ' ON balises (source, active)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX_NAME . ' ON balises');
    }
};
