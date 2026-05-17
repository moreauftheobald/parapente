<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Épingler un article en tête de la page d'accueil (bloc « philosophie »).
 * Une seule épingle active à la fois — contrainte garantie côté contrôleur
 * (ArticleController::store/update dépinglent les autres avant l'écriture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('is_published');
            $table->index('is_pinned');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['is_pinned']);
            $table->dropColumn('is_pinned');
        });
    }
};
