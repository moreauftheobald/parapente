<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journalise les pages vues (visites Blade) pour permettre les
 * tableaux de bord de fréquentation dans /admin/traffic.
 *
 * Conformité RGPD : pas de stockage d'IP, d'UA ou d'identifiant
 * persistant. Le `visitor_hash` est un sha256(ip + ua + sel
 * quotidien) — il permet de compter les visiteurs uniques sur la
 * journée mais ne permet PAS de tracer un visiteur d'un jour à
 * l'autre (le sel tourne à minuit).
 *
 * Pas de cookie posé → pas de bannière de consentement nécessaire.
 *
 * Rétention par défaut : 365 jours (configurable via le setting
 * `pageviews.retention_days`). La purge est faite par
 * `PurgePageViewsJob` programmé quotidiennement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();

            $table->timestamp('visited_at');

            // Identifiant pseudonyme à sel quotidien (sha256, 64 chars).
            // Compactable en binary(32) plus tard si besoin — on garde
            // string pour la lisibilité phpMyAdmin.
            $table->string('visitor_hash', 64);

            // Authentifié au moment de la visite (nullable pour invités).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('path', 255);                  // ex: /carte, /admin/sync
            $table->string('method', 8)->default('GET');  // GET/HEAD
            $table->unsignedSmallInteger('status_code')->default(200);

            // Catégorisation appareil (parseur maison sur user_agent)
            $table->enum('device_type', ['desktop', 'mobile', 'tablet', 'bot', 'unknown'])
                  ->default('unknown');
            $table->string('os', 32)->nullable();         // Windows, macOS, Linux, iOS, Android, Other
            $table->string('browser', 32)->nullable();    // Chrome, Firefox, Safari, Edge, Other

            // Référent normalisé (host uniquement, pas l'URL complète)
            $table->string('referer_host', 191)->nullable();

            // Indexes : tous les agrégats du dashboard filtrent sur
            // visited_at, et on a besoin d'un index séparé pour les
            // uniques visiteurs/jour (visitor_hash + DATE(visited_at)).
            $table->index('visited_at');
            $table->index(['visited_at', 'device_type'], 'pv_visited_device_idx');
            $table->index(['visitor_hash', 'visited_at'], 'pv_visitor_visited_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
