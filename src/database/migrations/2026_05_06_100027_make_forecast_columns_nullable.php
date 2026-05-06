<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecasts', function (Blueprint $table) {
            $table->unsignedSmallInteger('wind_direction')->nullable()->change();
            $table->decimal('wind_speed_avg', 5, 1)->nullable()->change();
            $table->decimal('wind_speed_min', 5, 1)->nullable()->change();
            $table->decimal('wind_speed_max', 5, 1)->nullable()->change();
            $table->decimal('temperature', 4, 1)->nullable()->change();
            $table->unsignedTinyInteger('humidity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('forecasts', function (Blueprint $table) {
            $table->unsignedSmallInteger('wind_direction')->nullable(false)->change();
            $table->decimal('wind_speed_avg', 5, 1)->nullable(false)->change();
            $table->decimal('wind_speed_min', 5, 1)->nullable(false)->change();
            $table->decimal('wind_speed_max', 5, 1)->nullable(false)->change();
            $table->decimal('temperature', 4, 1)->nullable(false)->change();
            $table->unsignedTinyInteger('humidity')->nullable(false)->change();
        });
    }
};
