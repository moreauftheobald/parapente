<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_scores', function (Blueprint $table) {
            // Plafond de vol estimé (consensus voting logic) en mètres
            $table->unsignedSmallInteger('cloud_base_consensus')->nullable()->after('precip_consensus');
        });
    }

    public function down(): void
    {
        Schema::table('site_scores', function (Blueprint $table) {
            $table->dropColumn('cloud_base_consensus');
        });
    }
};
