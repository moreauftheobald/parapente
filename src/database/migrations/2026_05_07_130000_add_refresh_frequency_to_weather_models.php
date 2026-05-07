<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute `refresh_frequency_minutes` à weather_models.
 *
 * Permettra à terme d'adapter la cadence de fetch par modèle (les
 * runs Open-Meteo ne sortent pas tous à la même fréquence : AROME et
 * ICON-D2 horaires, ICON-EU 3h, ECMWF/GFS 6h). Pour l'instant le
 * scheduler appelle FetchForecastsJob de manière uniforme toutes les
 * heures — ce champ servira à piloter une logique conditionnelle
 * dans une session future.
 *
 * Valeur par défaut : 60 (= comportement actuel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->unsignedSmallInteger('refresh_frequency_minutes')
                  ->default(60)
                  ->after('weight_medium');
        });
    }

    public function down(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->dropColumn('refresh_frequency_minutes');
        });
    }
};
