<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteScore;
use Illuminate\Http\JsonResponse;

class SiteController extends Controller
{
    /**
     * Retourne tous les sites actifs avec leur prochain score.
     */
    public function index(): JsonResponse
    {
        $sites = Site::active()
            ->with(['conditions', 'scores' => function ($query) {
                $query->upcoming()
                    ->orderBy('forecast_at')
                    ->limit(120); // 5 jours × 24h
            }])
            ->get()
            ->map(function (Site $site) {
                // Prochain créneau avec un statut défini
                $nextScore = $site->scores
                    ->where('forecast_at', '>=', now()->startOfHour())
                    ->first();

                return [
                    'id'         => $site->id,
                    'name'       => $site->name,
                    'lat'        => (float) $site->latitude,
                    'lng'        => (float) $site->longitude,
                    'altitude'   => $site->altitude_m,
                    'level'      => $site->level,
                    'region'     => $site->region,
                    'status'     => $nextScore?->status ?? 'unknown',
                    'confidence' => $nextScore?->confidence_pct ?? 0,
                    'next_slot'  => $nextScore?->forecast_at?->format('Y-m-d H:i'),
                    'wind_dir'   => $nextScore?->wind_dir_consensus,
                    'wind_speed' => $nextScore?->wind_speed_consensus,
                ];
            });

        return response()->json($sites);
    }

    /**
     * Retourne les scores des 5 prochains jours pour un site.
     */
    public function scores(int $id): JsonResponse
    {
        $site = Site::active()->findOrFail($id);

        $scores = SiteScore::where('site_id', $id)
            ->upcoming()
            ->orderBy('forecast_at')
            ->get()
            ->map(fn ($score) => [
                'forecast_at'   => $score->forecast_at->format('Y-m-d H:i'),
                'day'           => $score->forecast_at->format('d/m'),
                'hour'          => $score->forecast_at->format('H:i'),
                'status'        => $score->status,
                'confidence'    => $score->confidence_pct,
                'wind_dir'      => $score->wind_dir_consensus,
                'wind_speed'    => $score->wind_speed_consensus,
                'precip'        => $score->precip_consensus,
                'models_count'  => $score->models_count,
                'models_conv'   => $score->models_converging,
            ]);

        return response()->json([
            'site'   => [
                'id'       => $site->id,
                'name'     => $site->name,
                'altitude' => $site->altitude_m,
                'level'    => $site->level,
            ],
            'scores' => $scores,
        ]);
    }
}
