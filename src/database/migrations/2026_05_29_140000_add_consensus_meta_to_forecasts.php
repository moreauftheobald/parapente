<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute les métadonnées de consensus sur `forecasts`.
 *
 * Ces deux colonnes ne sont alimentées QUE par le modèle
 * `qui_vole_consensus` (consensus pré-calculé par le sidecar
 * `parapente-consensus-grid`, exposé via son API compatible Open-Meteo
 * sous les champs horaires `qui_vole_models_count` /
 * `qui_vole_models_converging`). Pour tous les autres modèles NWP elles
 * restent nulles.
 *
 * Le `ScoringService` les lit pour dériver `confidence_pct` et
 * `models_count` / `models_converging` quand le consensus provient de
 * l'API (cf. bascule du calcul de consensus vers le sidecar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecasts', function (Blueprint $table) {
            $table->unsignedSmallInteger('models_count')->nullable()->after('humidity');
            $table->unsignedSmallInteger('models_converging')->nullable()->after('models_count');
        });
    }

    public function down(): void
    {
        Schema::table('forecasts', function (Blueprint $table) {
            $table->dropColumn(['models_count', 'models_converging']);
        });
    }
};
