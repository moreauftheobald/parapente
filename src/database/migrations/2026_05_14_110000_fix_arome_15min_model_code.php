<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correction du code du modèle AROME-HD 15min.
 *
 * L'instance Open-Meteo self-hosted sert ce modèle sous le code
 * `meteofrance_arome_france_hd_15min` (avec "in"), alors que la
 * configuration initiale utilisait `meteofrance_arome_france_hd_15m`
 * (sans "in"). Résultat : les fetches retournaient systématiquement
 * une réponse vide (≈ 0 % de couverture).
 *
 * Cette migration aligne le code en base. La table `forecasts` et
 * `forecast_archive_balises` ne référencent que `weather_model_id`,
 * donc l'historique reste cohérent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // S'il existe déjà une ligne avec le code corrigé (cas d'un
        // environnement reseed récemment), on supprime simplement
        // l'ancien doublon pour éviter le conflit d'unicité.
        $newExists = DB::table('weather_models')
            ->where('code', 'meteofrance_arome_france_hd_15min')
            ->exists();

        if ($newExists) {
            DB::table('weather_models')
                ->where('code', 'meteofrance_arome_france_hd_15m')
                ->delete();
            return;
        }

        DB::table('weather_models')
            ->where('code', 'meteofrance_arome_france_hd_15m')
            ->update(['code' => 'meteofrance_arome_france_hd_15min']);
    }

    public function down(): void
    {
        DB::table('weather_models')
            ->where('code', 'meteofrance_arome_france_hd_15min')
            ->update(['code' => 'meteofrance_arome_france_hd_15m']);
    }
};
