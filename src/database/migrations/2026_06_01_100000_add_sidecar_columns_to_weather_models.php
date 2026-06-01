<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->float('weight_factor')->default(1.0)->after('weight_medium');
            $table->text('notes_admin')->nullable()->after('weight_factor');
        });
    }

    public function down(): void
    {
        Schema::table('weather_models', function (Blueprint $table) {
            $table->dropColumn(['weight_factor', 'notes_admin']);
        });
    }
};
