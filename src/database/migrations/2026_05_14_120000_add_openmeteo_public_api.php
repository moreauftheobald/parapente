<?php

declare(strict_types=1);

use App\Models\WeatherApi;
use App\Models\WeatherModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute l'API publique Open-Meteo (`api.open-meteo.com`) comme
 * second WeatherApi, et y route UKMO Global.
 *
 * Motif : sur l'instance self-hosted, UKMO Global n'expose pas
 * `wind_speed_10m` ni `wind_direction_10m` (interpolation à 10 m non
 * effectuée pour ce modèle), ce qui le rend inutilisable pour le
 * scoring parapente. L'API publique, elle, renvoie correctement ces
 * variables. Volume de requêtes attendu : ~80 req/jour pour UKMO
 * (4 fetches/jour × 14 sites + 24 batches balises), très en deçà
 * du quota 10 000 req/jour du tier gratuit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Insert ou récupère l'ID de l'API publique
        $publicId = DB::table('weather_apis')->where('code', 'openmeteo_public')->value('id');
        if ($publicId === null) {
            $publicId = DB::table('weather_apis')->insertGetId([
                'code'        => 'openmeteo_public',
                'name'        => 'Open-Meteo (API publique)',
                'base_url'    => 'https://api.open-meteo.com/v1',
                'auth_type'   => 'none',
                'daily_quota' => 10000,
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Route UKMO Global sur cette API. Idempotent : si le code du
        // modèle n'existe pas en base (cas d'une fresh install qui
        // n'a pas encore seedé WeatherModelSeeder), on ne fait rien.
        DB::table('weather_models')
            ->where('code', 'ukmo_global_deterministic_10km')
            ->update([
                'weather_api_id' => $publicId,
                'updated_at'     => now(),
            ]);
    }

    public function down(): void
    {
        // Rebascule UKMO sur l'API self-hosted (recherche par code)
        $selfHostedId = DB::table('weather_apis')->where('code', 'openmeteo')->value('id');
        if ($selfHostedId !== null) {
            DB::table('weather_models')
                ->where('code', 'ukmo_global_deterministic_10km')
                ->update([
                    'weather_api_id' => $selfHostedId,
                    'updated_at'     => now(),
                ]);
        }

        DB::table('weather_apis')->where('code', 'openmeteo_public')->delete();
    }
};
