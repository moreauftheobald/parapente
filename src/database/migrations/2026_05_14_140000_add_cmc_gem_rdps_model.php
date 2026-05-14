<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute le modèle `cmc_gem_rdps` (GEM Regional Deterministic
 * Prediction System du CMC Canada — 10 km, 84h, 4 runs/jour).
 *
 * Inactif par défaut : couverture limitée à l'Amérique du Nord
 * (inutile pour les sites France/Bénélux actuels). À activer
 * depuis `/admin/models` si des sites canadiens sont ajoutés.
 *
 * Idempotent : updateOrInsert sur le code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $openMeteoId = DB::table('weather_apis')->where('code', 'openmeteo')->value('id');
        if ($openMeteoId === null) {
            return;
        }

        DB::table('weather_models')->updateOrInsert(
            ['code' => 'cmc_gem_rdps'],
            [
                'name'                      => 'GEM RDPS',
                'provider'                  => 'CMC Canada',
                'resolution_km'             => 10.0,
                'max_horizon_h'             => 84,
                'weight_short'              => 0.80,
                'weight_medium'             => 0.00,
                'refresh_frequency_minutes' => 360,
                'active'                    => false,
                'weather_api_id'            => $openMeteoId,
                'updated_at'                => now(),
                'created_at'                => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('weather_models')->where('code', 'cmc_gem_rdps')->delete();
    }
};
