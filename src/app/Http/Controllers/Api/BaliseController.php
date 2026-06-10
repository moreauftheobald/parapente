<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\BaliseReading;
use App\Services\Balises\BaliseReadingFormatter;
use App\Services\Map\BalisesBundleCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 *
 * Les deux endpoints sont cachés via BalisesBundleCache (TTL 5 min pour
 * le bundle global, 2 min pour l'historique). Cf. FF_map_bundle_cache.md
 * (phase 3) — invalidation poussée par les jobs Fetch{PiouPiou,Metar,Windy}.
 */
class BaliseController extends Controller
{
    /** Fenêtre pour calculer la tendance (en minutes) */
    private const TREND_WINDOW_MINUTES = 30;

    /** Tolérance "stable" autour du diff = 0 (km/h) */
    private const TREND_LOW_THRESHOLD = 2.0;

    /** Seuil "marqué" (km/h) */
    private const TREND_HIGH_THRESHOLD = 5.0;

    public function __construct(
        private readonly BalisesBundleCache $cache,
    ) {}

    public function index(): JsonResponse
    {
        $payload = $this->cache->rememberBundle(fn () => $this->buildBundle());
        return response()->json($payload);
    }

    /**
     * Construction pure du bundle balises (cache miss). Retourne un
     * array natif PHP — pas d'objets Eloquent dans le cache
     * (cf. point 11 CLAUDE.md).
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildBundle(): array
    {
        // Eager-load la dernière heure de lectures par balise pour
        // permettre le calcul de tendance sans N+1.
        $balises = Balise::active()
            ->with(['readings' => function ($q) {
                $q->where('read_at', '>=', now()->subHour())
                  ->orderBy('read_at', 'desc');
            }])
            ->get();

        return $balises->map(function (Balise $b) {
            $readings = $b->readings;
            $latest   = $readings->first();
            $reading  = null;

            if ($latest) {
                // On cherche la lecture la plus récente strictement
                // avant la fenêtre de tendance pour comparer.
                $cutoff = $latest->read_at->copy()->subMinutes(self::TREND_WINDOW_MINUTES - 5);
                $prev   = $readings->first(fn ($r) => $r->read_at->lessThanOrEqualTo($cutoff));

                $reading = [
                    'read_at' => $latest->read_at->toIso8601String(),
                    ...BaliseReadingFormatter::meteorology($latest),
                    'trend'   => $this->computeTrend($latest, $prev),
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
        })->values()->all();
    }

    /**
     * Historique des relevés du jour pour une balise (popup carte).
     *
     * GET /api/balises/{id}/history
     *
     * Renvoie les métadonnées de la balise, le dernier relevé et la
     * série des relevés depuis minuit (heure de Paris). Si la balise
     * n'a rien émis aujourd'hui, on remonte jusqu'à 24 h en arrière
     * pour avoir tout de même quelque chose à afficher.
     *
     * Caché 2 min (TTL court car les graphes doivent refléter les
     * nouveaux relevés rapidement).
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $balise = Balise::active()->findOrFail($id);

        // Fenêtre optionnelle pour la rose fréquentielle (popup carte) :
        // 3 / 6 / 12 / 24 / 72 h glissantes. Sans param → comportement
        // historique (relevés du jour + fallback 24 h).
        $window = (int) $request->query('window', '0');
        $window = in_array($window, [3, 6, 12, 24, 72], true) ? $window : null;

        $payload = $this->cache->rememberHistory(
            $balise->id,
            fn () => $this->buildHistoryPayload($balise, $window),
            $window,
        );

        return response()->json($payload);
    }

    /**
     * Construction pure du payload d'historique d'une balise.
     *
     * @return array<string,mixed>
     */
    private function buildHistoryPayload(Balise $balise, ?int $windowHours = null): array
    {
        $tz       = new \DateTimeZone('Europe/Paris');
        $dayStart = Carbon::now($tz)->startOfDay();

        $cols = ['read_at', 'wind_direction', 'wind_speed_avg', 'wind_speed_min', 'wind_speed_max', 'temperature', 'humidity'];

        $fallback = false;

        if ($windowHours !== null) {
            // Mode fenêtre glissante (rose fréquentielle) : pas de fallback,
            // on prend tout ce qu'il y a sur les N dernières heures.
            $readings = $balise->readings()
                ->where('read_at', '>=', Carbon::now()->subHours($windowHours))
                ->orderBy('read_at')
                ->get($cols);
        } else {
            $readings = $balise->readings()
                ->where('read_at', '>=', $dayStart)
                ->orderBy('read_at')
                ->get($cols);

            if ($readings->isEmpty()) {
                $fallback = true;
                $readings = $balise->readings()
                    ->where('read_at', '>=', Carbon::now()->subDay())
                    ->orderBy('read_at')
                    ->get($cols);
            }
        }

        $latest = $balise->readings()->orderByDesc('read_at')->first($cols);

        $serialize = function (BaliseReading $r) use ($tz) {
            $local = $r->read_at->copy()->setTimezone($tz);
            return [
                'read_at'    => $r->read_at->toIso8601String(),
                'time'       => $local->format('H:i'),
                'min_of_day' => (int) $local->format('G') * 60 + (int) $local->format('i'),
                ...BaliseReadingFormatter::meteorology($r),
            ];
        };

        return [
            'balise' => [
                'id'          => $balise->id,
                'name'        => $balise->name,
                'source'      => $balise->source,
                'external_id' => $balise->external_id,
                'lat'         => (float) $balise->latitude,
                'lng'         => (float) $balise->longitude,
                'altitude_m'  => $balise->altitude_m,
            ],
            'latest'   => $latest ? $serialize($latest) : null,
            'readings' => $readings->map($serialize)->values()->all(),
            'day'      => $dayStart->format('Y-m-d'),
            'fallback' => $fallback,
            'window'   => $windowHours,
        ];
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
