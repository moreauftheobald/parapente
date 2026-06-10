<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Couverture nuageuse par étage (basse / moyenne / haute) dans l'archive
 * des prévisions aux coordonnées des stations météo.
 *
 * Le consensus sidecar ne sert pas la couverture totale `cloud_cover`
 * mais sert les trois étages — on les archive donc séparément, pour le
 * consensus comme pour les modèles individuels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_archive_stations', function (Blueprint $table) {
            $table->unsignedTinyInteger('cloud_cover_low')->nullable()->after('cloud_cover');
            $table->unsignedTinyInteger('cloud_cover_mid')->nullable()->after('cloud_cover_low');
            $table->unsignedTinyInteger('cloud_cover_high')->nullable()->after('cloud_cover_mid');
        });
    }

    public function down(): void
    {
        Schema::table('forecast_archive_stations', function (Blueprint $table) {
            $table->dropColumn(['cloud_cover_low', 'cloud_cover_mid', 'cloud_cover_high']);
        });
    }
};
