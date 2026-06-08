<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('label', 100);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('quality_axes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('quality_profiles')->cascadeOnDelete();
            $table->string('axis', 50);
            $table->unsignedTinyInteger('weight')->default(0);
            $table->json('scoring_curve');
            $table->timestamps();

            $table->unique(['profile_id', 'axis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_axes');
        Schema::dropIfExists('quality_profiles');
    }
};
