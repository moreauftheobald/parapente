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
            // Consensus des rafales (wind_speed_max) calculé via la même
            // voting logic que wind_speed_consensus (moyenne pondérée
            // par inverse du carré de l'écart à la médiane).
            $table->decimal('wind_gust_consensus', 5, 1)->nullable()->after('wind_speed_consensus');
        });
    }

    public function down(): void
    {
        Schema::table('site_scores', function (Blueprint $table) {
            $table->dropColumn('wind_gust_consensus');
        });
    }
};
