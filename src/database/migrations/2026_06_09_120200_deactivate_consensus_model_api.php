<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Désactive le modèle `qui_vole_consensus` et l'API `consensus`.
 *
 * Le consensus multi-modèles ET le scoring sont désormais entièrement
 * calculés par le sidecar `consensus-grid-v2`, qui écrit les statuts
 * directement dans `site_scores_{1,2}`. Laravel ne fetche donc plus ce
 * modèle (la classe `ConsensusApi` et `FetchConsensusBatchJob` ont été
 * supprimées). Les laisser actifs ferait planter l'orchestration
 * (résolution de l'API `consensus` introuvable dans le registry).
 *
 * On conserve les lignes (inactives) pour l'historique et les exclusions
 * par code (`WeatherModel::CONSENSUS_CODE`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('weather_models')
            ->where('code', 'qui_vole_consensus')
            ->update(['active' => false, 'updated_at' => now()]);

        DB::table('weather_apis')
            ->where('code', 'consensus')
            ->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Inverse fidèle — mais ne réactiver QUE si le code ConsensusApi /
        // FetchConsensusBatchJob est également restauré, sinon
        // l'orchestration plantera.
        DB::table('weather_models')
            ->where('code', 'qui_vole_consensus')
            ->update(['active' => true, 'updated_at' => now()]);

        DB::table('weather_apis')
            ->where('code', 'consensus')
            ->update(['active' => true, 'updated_at' => now()]);
    }
};
