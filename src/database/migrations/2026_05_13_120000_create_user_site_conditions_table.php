<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditions de vol favorables définies par un utilisateur, par site.
 *
 * Miroir de `site_conditions` avec PK composite logique (user_id, site_id)
 * et deux colonnes pour la rotation LRU des actifs (`is_active`,
 * `activated_at`). Cf. FF_personnal_scoring.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_site_conditions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('site_id')
                  ->constrained('sites')
                  ->cascadeOnDelete();

            $table->boolean('is_active')->default(false);
            $table->dateTime('activated_at')->nullable();

            // Identiques à site_conditions
            $table->unsignedSmallInteger('wind_dir_min');
            $table->unsignedSmallInteger('wind_dir_max');
            $table->unsignedTinyInteger('wind_speed_min');
            $table->unsignedTinyInteger('wind_speed_max');
            $table->unsignedTinyInteger('wind_speed_ideal');
            $table->decimal('wind_gust_orange_kmh', 4, 1)->nullable();
            $table->decimal('wind_gust_red_kmh', 4, 1)->nullable();
            $table->unsignedSmallInteger('cloud_base_min_m')->nullable();
            $table->unsignedTinyInteger('cloud_cover_low_max')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'site_id']);
            // Requête LRU : WHERE user_id=? AND is_active=1 ORDER BY activated_at ASC LIMIT 1
            $table->index(['user_id', 'is_active', 'activated_at'], 'usc_user_active_lru_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_site_conditions');
    }
};
