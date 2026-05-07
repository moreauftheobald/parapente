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
        DB::statement("ALTER TABLE balises MODIFY source VARCHAR(20) NOT NULL");
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE balises MODIFY source ENUM('pioupiou','ffvl','windguru','netatmo','autre') NOT NULL"
        );
    }
};
