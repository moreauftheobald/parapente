<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pseudo')->nullable()->unique()->after('name');
            $table->text('bio')->nullable()->after('pseudo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pseudo']);
            $table->dropColumn(['pseudo', 'bio']);
        });
    }
};
