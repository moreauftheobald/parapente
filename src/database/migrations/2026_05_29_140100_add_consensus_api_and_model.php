<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enregistre le fournisseur API « Consensus » et le modèle météo
 * `qui_vole_consensus`.
 *
 * Le sidecar `parapente-consensus-grid` calcule le consensus
 * multi-modèles sur toute la France et l'expose via une API compatible
 * Open-Meteo (`GET /v1/forecast`). Côté Laravel, ce consensus devient un
 * modèle météo à part entière : `OpenMeteoApi`-like (classe dédiée
 * `ConsensusApi`) le fetch comme n'importe quel autre modèle, et le
 * `ScoringService` l'utilise **en priorité** comme valeur de consensus
 * (fallback sur le calcul interne si l'API est indisponible).
 *
 * L'endpoint/port est éditable depuis `/admin/apis` (champ `base_url`),
 * comme l'API Open-Meteo interne.
 *
 * Idempotent — `updateOrInsert` sur les clés uniques (`code`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('weather_apis') || ! Schema::hasTable('weather_models')) {
            return;
        }

        DB::table('weather_apis')->updateOrInsert(
            ['code' => 'consensus'],
            [
                'name'        => 'Consensus Qui-Vole (sidecar)',
                'base_url'    => env('CONSENSUS_API_URL', 'http://parapente-consensus-grid:8082/v1'),
                'auth_type'   => 'none',
                'daily_quota' => null,
                'active'      => true,
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        $consensusApiId = DB::table('weather_apis')->where('code', 'consensus')->value('id');

        DB::table('weather_models')->updateOrInsert(
            ['code' => 'qui_vole_consensus'],
            [
                'name'                      => 'Consensus Qui-Vole',
                'provider'                  => 'Qui-Vole',
                'weather_api_id'            => $consensusApiId,
                'resolution_km'             => 2.5,
                'max_horizon_h'             => 120,
                // Poids non utilisés pour le scoring (le consensus est repris
                // tel quel), mais 1.0 garde une sémantique propre si jamais il
                // repassait dans la voting logic.
                'weight_short'              => 1.00,
                'weight_medium'             => 1.00,
                'refresh_frequency_minutes' => 60,
                'active'                    => true,
                'updated_at'                => now(),
                'created_at'                => now(),
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('weather_models')) {
            DB::table('weather_models')->where('code', 'qui_vole_consensus')->delete();
        }
        if (Schema::hasTable('weather_apis')) {
            DB::table('weather_apis')->where('code', 'consensus')->delete();
        }
    }
};
