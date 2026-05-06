<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteScore;
use Illuminate\Http\JsonResponse;

class SiteController extends Controller
{
    public function index(): JsonResponse
    {
        $sites = Site::active()->get()->map(fn (Site $site) => [
            'id'       => $site->id,
            'name'     => $site->name,
            'lat'      => (float) $site->latitude,
            'lng'      => (float) $site->longitude,
            'altitude' => $site->altitude_m,
            'level'    => $site->level,
            'region'   => $site->region,
        ]);
        return response()->json($sites);
    }

    public function scores(int $id): JsonResponse
    {
        $site = Site::active()->findOrFail($id);
        $allScores = SiteScore::where('site_id', $id)->upcoming()->orderBy('forecast_at')->get();
        $grouped = $allScores->groupBy(fn ($s) => $s->forecast_at->format('d/m'));
        $scores = collect();
        $sunWindows = [];
        foreach ($grouped as $day => $dayScores) {
            $window = $this->getSunWindow((float) $site->latitude, (float) $site->longitude, $day);
            $sunWindows[$day] = $window;
            foreach ($dayScores as $score) {
                $h = (int) $score->forecast_at->format('G');
                if ($h >= $window['start_hour'] && $h <= $window['end_hour']) {
                    $scores->push($score);
                }
            }
        }
        return response()->json([
            'site'        => ['id' => $site->id, 'name' => $site->name, 'altitude' => $site->altitude_m, 'level' => $site->level],
            'scores'      => $scores->map(fn ($s) => [
                'forecast_at'  => $s->forecast_at->format('Y-m-d H:i'),
                'day'          => $s->forecast_at->format('d/m'),
                'hour'         => $s->forecast_at->format('H:i'),
                'status'       => $s->status,
                'confidence'   => $s->confidence_pct,
                'wind_dir'     => $s->wind_dir_consensus,
                'wind_speed'   => $s->wind_speed_consensus,
                'precip'       => $s->precip_consensus,
                'models_count' => $s->models_count,
                'models_conv'  => $s->models_converging,
            ])->values(),
            'sun_windows' => $sunWindows,
        ]);
    }

    /**
     * Données horaires agrégées pour le popup graphique :
     * vent min/moy/max, couverture nuageuse haute/moy/basse, direction.
     */
    public function chart(int $id): JsonResponse
    {
        $site = Site::active()->with('conditions')->findOrFail($id);

        // Scores (pour direction consensus + statut)
        $scores = SiteScore::where('site_id', $id)
            ->upcoming()
            ->orderBy('forecast_at')
            ->get()
            ->keyBy(fn ($s) => $s->forecast_at->format('Y-m-d H:i:s'));

        // Prévisions brutes agrégées (vent min/max + nuages)
        $rawForecasts = Forecast::where('site_id', $id)
            ->upcoming()
            ->whereNotNull('wind_speed_avg')
            ->get()
            ->groupBy(fn ($f) => $f->forecast_at->format('Y-m-d H:i:s'));

        $grouped    = $scores->groupBy(fn ($s) => $s->forecast_at->format('d/m'));
        $days       = [];
        $sunWindows = [];

        foreach ($grouped as $day => $dayScores) {
            $window         = $this->getSunWindow((float) $site->latitude, (float) $site->longitude, $day);
            $sunWindows[$day] = $window;
            $dayData        = [];

            foreach ($dayScores as $score) {
                $h = (int) $score->forecast_at->format('G');
                if ($h < $window['start_hour'] || $h > $window['end_hour']) continue;

                $key   = $score->forecast_at->format('Y-m-d H:i:s');
                $fcsts = ($rawForecasts[$key] ?? collect())->filter(fn ($f) => $f->wind_speed_avg !== null);

                $dayData[] = [
                    'hour'       => $score->forecast_at->format('H:i'),
                    'status'     => $score->status,
                    'confidence' => $score->confidence_pct,
                    'wind_dir'   => $score->wind_dir_consensus,
                    'wind_avg'   => $score->wind_speed_consensus ? round((float) $score->wind_speed_consensus, 1) : null,
                    'wind_min'   => $fcsts->isNotEmpty() ? round($fcsts->avg('wind_speed_min'), 1) : null,
                    'wind_max'   => $fcsts->isNotEmpty() ? round($fcsts->avg('wind_speed_max'), 1) : null,
                    'cloud_high' => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_high')) : null,
                    'cloud_mid'  => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_mid'))  : null,
                    'cloud_low'  => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_low'))  : null,
                ];
            }

            if (!empty($dayData)) $days[$day] = $dayData;
        }

        return response()->json([
            'site' => [
                'id'             => $site->id,
                'name'           => $site->name,
                'altitude'       => $site->altitude_m,
                'level'          => $site->level,
                'wind_dir_min'   => $site->conditions?->wind_dir_min,
                'wind_dir_max'   => $site->conditions?->wind_dir_max,
                'wind_speed_min' => $site->conditions?->wind_speed_min,
                'wind_speed_max' => $site->conditions?->wind_speed_max,
            ],
            'days'        => $days,
            'sun_windows' => $sunWindows,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function getSunWindow(float $lat, float $lng, string $day): array
    {
        $tz      = new \DateTimeZone('Europe/Paris');
        [$d, $m] = explode('/', $day);
        $ts      = \Carbon\Carbon::createFromDate((int) date('Y'), (int) $m, (int) $d, $tz)->setTime(12, 0)->getTimestamp();

        $sunriseTs = date_sunrise($ts, SUNFUNCS_RET_TIMESTAMP, $lat, $lng, 90.833);
        $sunsetTs  = date_sunset($ts,  SUNFUNCS_RET_TIMESTAMP, $lat, $lng, 90.833);

        $startDt   = (new \DateTime('@' . ($sunriseTs - 1800)))->setTimezone($tz);
        $startHour = (int) $startDt->format('G');

        $endDt   = (new \DateTime('@' . ($sunsetTs + 1800)))->setTimezone($tz);
        $endHour = (int) $endDt->format('G');
        if ((int) $endDt->format('i') > 0) $endHour = min(23, $endHour + 1);

        return [
            'sunrise_display' => (new \DateTime('@' . $sunriseTs))->setTimezone($tz)->format('H:i'),
            'sunset_display'  => (new \DateTime('@' . $sunsetTs))->setTimezone($tz)->format('H:i'),
            'start_hour'      => $startHour,
            'end_hour'        => $endHour,
        ];
    }
}
