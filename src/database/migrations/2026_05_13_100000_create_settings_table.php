<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table `settings` — paramètres globaux de l'application modifiables
 * depuis l'écran admin /admin/settings (seuils de scoring, viabilité du
 * jour, etc.). Clé unique + valeur JSON pour stocker n'importe quel type
 * scalaire ou array.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->string('label', 255)->nullable();    // libellé affiché dans l'admin
            $table->text('description')->nullable();     // aide contextuelle
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
