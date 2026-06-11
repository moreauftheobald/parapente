<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index couvrants pour l'écran « Data / couverture » (et plus largement
 * toute agrégation temporelle sur les grosses tables météo).
 *
 * Les index simples sur la colonne temporelle ne suffisent pas : les
 * agrégations (COUNT DISTINCT par modèle / unité) doivent alors relire
 * chaque LIGNE (millions de lookups sur forecast_archive_* à cause de
 * la dimension bucket) → timeout. Avec la colonne d'unité (et de modèle
 * pour les archives) DANS l'index, les requêtes deviennent « index-only ».
 *
 * Création en ligne (ALGORITHM=INPLACE, LOCK=NONE) : les lectures ET les
 * écritures concurrentes restent possibles pendant le build — pas de
 * « Waiting for metadata lock » qui figerait les jobs de fetch.
 *
 * Idempotent : chaque index n'est créé que s'il est absent — la
 * migration peut être relancée sans risque après une interruption ou
 * une création partielle.
 *
 * ⚠️ Sur forecast_archive_stations (~15 M lignes), le build peut prendre
 * plusieurs minutes — lancer hors pic. Conseillé : arrêter le worker et
 * le scheduler le temps de la migration.
 */
return new class extends Migration
{
    /** @var array<int, array{table:string, index:string, cols:string}> */
    private array $indexes = [
        ['table' => 'forecast_archive_balises',              'index' => 'fab_target_model_balise_idx',   'cols' => 'target_at, weather_model_id, balise_id'],
        ['table' => 'forecast_archive_stations',             'index' => 'fas_target_model_station_idx',  'cols' => 'target_at, weather_model_id, weather_station_id'],
        ['table' => 'balise_readings',                       'index' => 'br_read_balise_idx',            'cols' => 'read_at, balise_id'],
        ['table' => 'weather_station_observations',          'index' => 'wso_observed_station_idx',       'cols' => 'observed_at, weather_station_id'],
        ['table' => 'balise_readings_hourly',                'index' => 'brh_hour_balise_idx',           'cols' => 'hour_at, balise_id'],
        ['table' => 'weather_station_observations_hourly',   'index' => 'wsoh_hour_station_idx',          'cols' => 'hour_at, weather_station_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $idx) {
            if ($this->indexExists($idx['table'], $idx['index'])) {
                continue; // déjà créé (relance après interruption)
            }
            // Création en ligne : pas de verrou bloquant pour les workers.
            DB::statement(
                "ALTER TABLE `{$idx['table']}` ADD INDEX `{$idx['index']}` ({$idx['cols']}), ALGORITHM=INPLACE, LOCK=NONE"
            );
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $idx) {
            if ($this->indexExists($idx['table'], $idx['index'])) {
                DB::statement("ALTER TABLE `{$idx['table']}` DROP INDEX `{$idx['index']}`");
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );
        return ! empty($rows);
    }
};
