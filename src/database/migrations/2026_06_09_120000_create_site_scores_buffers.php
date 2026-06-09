<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables double-buffer `site_scores_1` / `site_scores_2` — désormais
 * écrites par le sidecar `consensus-grid-v2` (scoring déporté hors de
 * Laravel). Laravel ne fait plus que LIRE le buffer actif, désigné par
 * le setting `scoring_table` (cf. migration suivante).
 *
 * Schéma : miroir fidèle de la table template historique `site_scores`,
 * SANS la clé étrangère vers `sites` — exactement comme les tables que le
 * sidecar DROP/recrée à chaque run via `CREATE TABLE … LIKE site_scores`
 * (en MariaDB, `CREATE … LIKE` ne recopie pas les FK). On reconstruit le
 * schéma colonne par colonne plutôt que d'émettre un `CREATE … LIKE` ici,
 * pour rester portable (les tests tournent sur SQLite, qui ne connaît pas
 * cette syntaxe). En prod, le premier run du sidecar remplacera de toute
 * façon ces tables par son propre clone.
 *
 * `site_scores` reste en place, vidée : elle sert de source DDL au
 * bootstrap du sidecar (fallback `CREATE … LIKE site_scores`).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['site_scores_1', 'site_scores_2'] as $name) {
            if (Schema::hasTable($name)) {
                continue;
            }

            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();

                // Pas de FK (buffer DROP/recréé par le sidecar) — juste la colonne + index.
                $table->unsignedBigInteger('site_id');

                $table->dateTime('forecast_at');
                $table->dateTime('computed_at');

                $table->enum('status', ['green', 'orange', 'red', 'unknown'])->default('unknown');
                $table->unsignedTinyInteger('confidence_pct')->default(0);

                $table->unsignedSmallInteger('wind_dir_consensus')->nullable();   // degrés
                $table->decimal('wind_speed_consensus', 5, 1)->nullable();        // km/h
                $table->decimal('wind_gust_consensus', 5, 1)->nullable();         // km/h
                $table->decimal('precip_consensus', 5, 1)->nullable();            // mm/h
                $table->unsignedSmallInteger('cloud_base_consensus')->nullable(); // m AMSL

                $table->unsignedTinyInteger('models_count');
                $table->unsignedTinyInteger('models_converging');

                $table->json('detail')->nullable();
                $table->json('quality_detail')->nullable(); // scores qualité par profil (sidecar)

                $table->timestamps();

                $table->unique(['site_id', 'forecast_at'], "{$name}_unique");
                $table->index(['site_id', 'forecast_at', 'status'], "{$name}_sfs_idx");
                $table->index('forecast_at', "{$name}_fa_idx");
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_scores_1');
        Schema::dropIfExists('site_scores_2');
    }
};
