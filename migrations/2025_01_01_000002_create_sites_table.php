<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();

            // Identité
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('region')->default('grand-est');

            // Décollage
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('altitude_m');

            // Atterrissage
            $table->decimal('landing_lat', 10, 7)->nullable();
            $table->decimal('landing_lng', 10, 7)->nullable();

            // Niveau requis
            $table->enum('level', [
                'initiation',
                'debutant',
                'intermediaire',
                'confirme',
                'expert',
            ])->default('intermediaire');

            // Gestion
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->boolean('active')->default(true);

            $table->timestamps();

            // Index
            $table->index('region');
            $table->index('active');
            $table->index(['active', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
