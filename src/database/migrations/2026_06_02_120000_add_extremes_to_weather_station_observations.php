<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_station_observations', function (Blueprint $table) {
            $table->decimal('temperature_min', 5, 1)->nullable()->after('temperature');
            $table->decimal('temperature_max', 5, 1)->nullable()->after('temperature_min');
            $table->unsignedTinyInteger('humidity_min')->nullable()->after('humidity');
            $table->unsignedTinyInteger('humidity_max')->nullable()->after('humidity_min');
            $table->decimal('wind_speed_max_10m', 5, 1)->nullable()->after('wind_speed_max');
            $table->unsignedSmallInteger('wind_direction_max')->nullable()->after('wind_speed_max_10m');
            $table->unsignedSmallInteger('wind_direction_gust')->nullable()->after('wind_direction_max');
        });
    }

    public function down(): void
    {
        Schema::table('weather_station_observations', function (Blueprint $table) {
            $table->dropColumn([
                'temperature_min', 'temperature_max',
                'humidity_min', 'humidity_max',
                'wind_speed_max_10m', 'wind_direction_max', 'wind_direction_gust',
            ]);
        });
    }
};
