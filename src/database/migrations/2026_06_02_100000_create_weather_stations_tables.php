<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_stations', function (Blueprint $table) {
            $table->id();
            $table->string('network', 20);
            $table->string('external_id', 60);
            $table->string('name', 255)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('altitude_m')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('admin_region', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('in_reliability_panel')->default(false);
            $table->json('metadata')->nullable();
            $table->dateTime('last_obs_at')->nullable();
            $table->timestamps();

            $table->unique(['network', 'external_id']);
            $table->index('active');
            $table->index(['network', 'active']);
            $table->index('last_obs_at');
            $table->index('country_code');
            $table->index('department');
            $table->index(['country_code', 'admin_region']);
        });

        Schema::create('weather_station_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weather_station_id')->constrained()->cascadeOnDelete();
            $table->dateTime('observed_at');
            $table->unsignedSmallInteger('wind_direction')->nullable();
            $table->decimal('wind_speed_avg', 5, 1)->nullable();
            $table->decimal('wind_speed_max', 5, 1)->nullable();
            $table->decimal('temperature', 5, 1)->nullable();
            $table->unsignedTinyInteger('humidity')->nullable();
            $table->decimal('pressure_hpa', 6, 1)->nullable();
            $table->decimal('precipitation_mm', 5, 1)->nullable();
            $table->unsignedTinyInteger('cloud_cover_pct')->nullable();
            $table->unsignedInteger('visibility_m')->nullable();
            $table->decimal('dew_point', 5, 1)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['weather_station_id', 'observed_at'], 'ws_obs_station_time_unique');
            $table->index('observed_at', 'ws_obs_observed_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_station_observations');
        Schema::dropIfExists('weather_stations');
    }
};
