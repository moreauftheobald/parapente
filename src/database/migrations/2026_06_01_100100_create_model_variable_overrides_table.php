<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_variable_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('model_code', 64);
            $table->string('variable', 64);
            $table->boolean('enabled')->default(true);
            $table->text('notes_admin')->nullable();
            $table->timestamps();

            $table->unique(['model_code', 'variable'], 'uniq_model_var');
            $table->index('model_code', 'idx_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_variable_overrides');
    }
};
