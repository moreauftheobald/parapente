<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convertit balises.source d'ENUM (liste fermée) vers VARCHAR(20).
 *
 * L'ENUM initial ne contenait pas 'metar' — l'ajout de chaque source
 * (METAR, Holfuy, etc.) imposait sinon une nouvelle migration ALTER
 * et un déploiement coordonné. VARCHAR rend l'ajout d'une source aussi
 * simple que de créer un nouveau Provider.
 *
 * Les valeurs existantes ('pioupiou', 'ffvl', ...) sont préservées
 * inchangées par MariaDB lors du changement de type.
 *
 * On utilise DB::statement plutôt que ->change() pour ne pas imposer
 * doctrine/dbal en dépendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip sous SQLite (tests) : pas de support du ALTER ... MODIFY,
        // et pas non plus d'ENUM natif — la colonne créée par
        // `create_balises_table` reste compatible (VARCHAR ou TEXT).
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE balises MODIFY source VARCHAR(20) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                "ALTER TABLE balises MODIFY source ENUM('pioupiou','ffvl','windguru','netatmo','autre') NOT NULL"
            );
        }
    }
};
