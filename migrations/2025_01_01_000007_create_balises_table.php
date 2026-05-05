<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balises', function (Blueprint $table) {
            $table->id();

            // Une balise peut être rattachée à un site (nullable : balise proche mais pas sur le site)
            $table->foreignId('site_id')
                  ->nullable()
                  ->constrained('sites')
                  ->nullOnDelete();

            // Source de la balise
            $table->enum('source', ['pioupiou', 'ffvl', 'windguru', 'netatmo', 'autre']);

            // ID dans le système source (ex: "1234" pour PiouPiou)
            $table->string('external_id');

            $table->string('name');

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('altitude_m')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            // Une balise = un external_id unique par source
            $table->unique(['source', 'external_id']);
            $table->index('site_id');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balises');
    }
};
