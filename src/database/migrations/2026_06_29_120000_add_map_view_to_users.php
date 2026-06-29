<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mémorise la dernière vue de la carte de volabilité par utilisateur
 * ({lat, lng, zoom, basemap}). Permet de resservir au pilote connecté sa
 * position/zoom à sa prochaine visite, y compris depuis un autre appareil.
 * Les invités s'appuient sur localStorage côté client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('map_view')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('map_view');
        });
    }
};
