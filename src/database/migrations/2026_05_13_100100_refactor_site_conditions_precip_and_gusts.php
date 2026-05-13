<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte des conditions de site liées à la pluie et aux rafales :
 *
 *  - Retire `precip_max` (les seuils de précipitations deviennent globaux,
 *    stockés dans la table `settings` : scoring.precip_orange_mmh /
 *    scoring.precip_red_mmh).
 *  - Ajoute `wind_gust_orange_kmh` et `wind_gust_red_kmh` (nullable) :
 *    surcharge par site des seuils globaux de rafales (pour les sites
 *    plus exposés ou plus indulgents). NULL = valeur globale (settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_conditions', function (Blueprint $table) {
            $table->dropColumn('precip_max');
            $table->decimal('wind_gust_orange_kmh', 5, 1)->nullable()->after('wind_speed_ideal');
            $table->decimal('wind_gust_red_kmh',    5, 1)->nullable()->after('wind_gust_orange_kmh');
        });
    }

    public function down(): void
    {
        Schema::table('site_conditions', function (Blueprint $table) {
            $table->dropColumn(['wind_gust_orange_kmh', 'wind_gust_red_kmh']);
            $table->decimal('precip_max', 5, 1)->default(0.0)->after('wind_speed_ideal');
        });
    }
};
