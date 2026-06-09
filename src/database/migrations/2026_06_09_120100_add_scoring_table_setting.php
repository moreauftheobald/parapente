<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Setting `scoring_table` — pointeur du double-buffer de scoring actif
 * (« 1 » ou « 2 »). Écrit par le sidecar `consensus-grid-v2` après chaque
 * run (flip atomique), lu par Laravel pour savoir dans quelle table
 * (`site_scores_1` / `site_scores_2`) lire les scores.
 *
 * Contrat de stockage (cf. scoring_db.py) : la colonne `value` contient
 * le caractère ASCII « 1 » ou « 2 », pas un JSON wrappé. `1`/`2` étant du
 * JSON valide, la colonne JSON l'accepte ; côté Laravel on lit la valeur
 * brute en SQL direct (SiteScore::activeTableName) pour ne PAS passer par
 * le cache Redis 1 h du service Settings (le sidecar flippe en SQL direct,
 * le cache ne verrait pas le changement avant expiration).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'scoring_table'],
            [
                'value'       => '1',
                'label'       => 'Buffer de scoring actif',
                'description' => 'Pointeur du double-buffer écrit par le sidecar consensus-grid-v2 (« 1 » ou « 2 »). Laravel lit site_scores_{value}. Ne pas éditer à la main.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'scoring_table')->delete();
    }
};
