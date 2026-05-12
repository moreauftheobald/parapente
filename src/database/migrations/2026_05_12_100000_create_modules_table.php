<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules de l'application (entrées du menu principal).
 *
 * Remplace l'ancien fichier config/modules.php : la définition est
 * désormais en base et éditable depuis l'admin (/admin/modules).
 * L'affichage d'un module dans la barre de menu dépend de :
 *   - is_active
 *   - access_level (guest|user|admin)
 *   - requires_registration (utilisateur connecté obligatoire)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();          // identifiant technique (home, map, …)
            $table->string('label');
            $table->string('icon')->nullable();           // classe Font Awesome
            $table->string('route_name')->nullable();     // route Laravel (null = pas encore implémenté)
            $table->boolean('is_active')->default(true);
            $table->string('access_level', 16)->default('guest'); // guest|user|admin
            $table->boolean('requires_registration')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
