<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_stations', function (Blueprint $table) {
            $table->boolean('has_wind_sensor')->nullable()->default(null)->after('in_reliability_panel');
            $table->index('has_wind_sensor');
        });
    }

    public function down(): void
    {
        Schema::table('weather_stations', function (Blueprint $table) {
            $table->dropIndex(['has_wind_sensor']);
            $table->dropColumn('has_wind_sensor');
        });
    }
};
