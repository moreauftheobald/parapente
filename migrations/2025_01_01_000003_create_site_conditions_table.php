<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                  ->unique()          // 1 profil par site
                  ->constrained('sites')
                  ->cascadeOnDelete();

            // ── Vent (DÉTERMINISTE) ──────────────────────────────
            // Direction en degrés (0-359)
            // Si dir_min > dir_max : la plage chevauche le Nord (ex: 315-45)
            $table->unsignedSmallInteger('wind_dir_min');
            $table->unsignedSmallInteger('wind_dir_max');

            // Vitesse en km/h
            $table->unsignedSmallInteger('wind_speed_min');
            $table->unsignedSmallInteger('wind_speed_max');
            $table->unsignedSmallInteger('wind_speed_ideal');

            // ── Précipitations (DÉTERMINISTE) ────────────────────
            // Seuil maxi toléré en mm/h (généralement 0)
            $table->decimal('precip_max', 4, 1)->default(0.0);

            // ── Conditions qualitatives (PONDÉRÉES) ─────────────
            // Plafond nuageux minimum acceptable en mètres
            $table->unsignedSmallInteger('cloud_base_min_m')->nullable();

            // Couverture nuageuse maxi acceptable en % (basse couche)
            $table->unsignedTinyInteger('cloud_cover_low_max')->nullable();

            // Notes libres (accès, dangers particuliers, etc.)
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_conditions');
    }
};
