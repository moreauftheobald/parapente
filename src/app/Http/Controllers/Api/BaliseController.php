<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use Illuminate\Http\JsonResponse;

/**
 * API JSON des balises météo (PiouPiou pour la phase 1.1, FFVL/METAR
 * en phase 1.2/1.3).
 *
 * GET /api/balises
 *
 * Retourne la liste des balises actives avec leur dernière lecture
 * et une tendance pré-calculée sur ~30 minutes (-2 à +2). Les balises
 * sans lecture < 7 jours ne sont pas exposées (déjà filtrées par
 * active=false en base, désactivation auto par FetchPiouPiouReadingsJob).
 */
class BaliseController extends Controller
{
    /** Fenêtre pour calculer la tendance (en minutes) */
    private const TREND_WINDOW_MINUTES = 30;

    /** Tolérance "stable" autour du diff = 0 (km/h) */
    private const TREND_LOW_THRESHOLD = 2.0;

    /** Seuil "marqué" (km/h) */
    private const TREND_HIGH_THRESHOLD = 5.0;

    public function index(): JsonResponse
    {
        // Eager-load la dernière heure de lectures par balise pour
        // permettre le calcul de tendance sans N+1.
        $balises = Balise::active()
            ->with(['readings' => function ($q) {
                $q->where('read_at', '>=', now()->subHour())
                  ->orderBy('read_at', 'desc');
            }])
            ->get();

        $payload = $balises->map(function (Balise $b) {
            $readings = $b->readings;
            $latest   = $readings->first();
            $reading  = null;

            if ($latest) {
                // On cherche la lecture la plus récente strictement
                // avant la fenêtre de tendance pour comparer.
                $cutoff = $latest->read_at->copy()->subMinutes(self::TREND_WINDOW_MINUTES - 5);
                $prev   = $readings->first(fn ($r) => $r->read_at->lessThanOrEqualTo($cutoff));

                $reading = [
                    'read_at'        => $latest->read_at->toIso8601String(),
                    'wind_direction' => $latest->wind_direction,
                    'wind_speed_avg' => $latest->wind_speed_avg !== null ? (float) $latest->wind_speed_avg : null,
                    'wind_speed_min' => $latest->wind_speed_min !== null ? (float) $latest->wind_speed_min : null,
                    'wind_speed_max' => $latest->wind_speed_max !== null ? (float) $latest->wind_speed_max : null,
                    'temperature'    => $latest->temperature !== null ? (float) $latest->temperature : null,
                    'humidity'       => $latest->humidity,
                    'trend'          => $this->computeTrend($latest, $prev),
                ];
            }

            return [
                'id'          => $b->id,
                'source'      => $b->source,
                'external_id' => $b->external_id,
                'name'        => $b->name,
                'lat'         => (float) $b->latitude,
                'lng'         => (float) $b->longitude,
                'altitude_m'  => $b->altitude_m,
                'reading'     => $reading,
            ];
        })->values();

        return response()->json($payload);
    }

    /**
     * Tendance de vitesse de vent : -2 (baisse marquée) à +2 (hausse
     * marquée). 0 si données insuffisantes pour comparer.
     */
    private function computeTrend($latest, $prev): int
    {
        if (! $prev || $latest->wind_speed_avg === null || $prev->wind_speed_avg === null) {
            return 0;
        }
        $diff = (float) $latest->wind_speed_avg - (float) $prev->wind_speed_avg;
        if ($diff >  self::TREND_HIGH_THRESHOLD) return 2;
        if ($diff >  self::TREND_LOW_THRESHOLD)  return 1;
        if ($diff < -self::TREND_HIGH_THRESHOLD) return -2;
        if ($diff < -self::TREND_LOW_THRESHOLD)  return -1;
        return 0;
    }
}
