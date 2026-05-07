<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rend `sites.region` nullable.
 *
 * À l'origine la région était un champ obligatoire (les 14 sites Grand
 * Est seedés avaient tous 'grand-est'). Avec l'import ParaglidingEarth,
 * on récupère des sites de toute la France (et potentiellement d'autres
 * pays plus tard) sans information de région française fiable. Plutôt
 * que d'inventer une valeur, on accepte null et on enrichira plus tard
 * (par ex. via reverse geocoding depuis lat/lng dans le BackOffice).
 *
 * On utilise DB::statement pour ne pas imposer doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE sites MODIFY region VARCHAR(255) NULL");
    }

    public function down(): void
    {
        // Rétablit NOT NULL (les rows à NULL doivent avoir été remplies
        // au préalable, sinon le ALTER échouera).
        DB::statement("ALTER TABLE sites MODIFY region VARCHAR(255) NOT NULL");
    }
};
