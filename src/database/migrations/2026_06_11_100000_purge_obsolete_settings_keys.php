<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Purge des clés `settings` obsolètes (audit du 2026-06-11,
 * cf. SIDECAR_SETTINGS_REVIEW.md) :
 *
 * - `consensus.global.*` (2) et `consensus.scheduler.*` (10) : jamais
 *   lues par le sidecar (méthode par défaut codée en dur côté sidecar,
 *   scheduler = daemon autonome configuré par variables d'environnement).
 * - `stations.fetch_enabled` / `stations.retention_days` : jamais
 *   consommées par Laravel (kill switches réels = station_apis.active,
 *   rétentions = constantes de PurgeOldForecastsJob).
 *
 * Les entrées correspondantes ont été retirées de Settings::DEFAULTS —
 * cette migration nettoie les lignes résiduelles en base.
 */
return new class extends Migration
{
    private const OBSOLETE_KEYS = [
        'consensus.global.default_method',
        'consensus.global.preview_enabled',
        'consensus.scheduler.mode',
        'consensus.scheduler.cron_minute',
        'consensus.scheduler.event_debounce_seconds',
        'consensus.scheduler.safety_net_hours',
        'consensus.scheduler.priority_horizon_J',
        'consensus.scheduler.priority_horizon_J1',
        'consensus.scheduler.priority_horizon_J2',
        'consensus.scheduler.priority_horizon_J3',
        'consensus.scheduler.priority_horizon_J4',
        'consensus.scheduler.priority_derived',
        'stations.fetch_enabled',
        'stations.retention_days',
    ];

    public function up(): void
    {
        DB::table('settings')->whereIn('key', self::OBSOLETE_KEYS)->delete();
        Cache::forget('app:settings:all');
    }

    public function down(): void
    {
        // Suppression de données volontaire — pas de retour arrière
        // (les défauts vivaient dans Settings::DEFAULTS, désormais retirés).
    }
};
