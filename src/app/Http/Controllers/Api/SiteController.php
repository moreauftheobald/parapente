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
     * Retourne tous les sites actifs (métadonnées uniquement).
     */
    public function index(): JsonResponse
    {
        $sites = Site::active()
            ->get()
            ->map(fn (Site $site) => [
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

    /**
     * Retourne les scores filtrés sur la fenêtre de vol solaire.
     *
     * Règle :
     *   Début : lever - 30min → floor à l'heure (ex: 06:40 → 06h)
     *   Fin   : coucher + 30min → ceil à l'heure (ex: 18:50 → 19h)
     */
    public function scores(int $id): JsonResponse
    {
        $site = Site::active()->findOrFail($id);

        $allScores = SiteScore::where('site_id', $id)
            ->upcoming()
            ->orderBy('forecast_at')
            ->get();

        $grouped    = $allScores->groupBy(fn ($s) => $s->forecast_at->format('d/m'));
        $scores     = collect();
        $sunWindows = [];

        foreach ($grouped as $day => $dayScores) {
            $window         = $this->getSunWindow((float) $site->latitude, (float) $site->longitude, $day);
            $sunWindows[$day] = $window;

            foreach ($dayScores as $score) {
                $hour = (int) $score->forecast_at->format('G');
                if ($hour >= $window['start_hour'] && $hour <= $window['end_hour']) {
                    $scores->push($score);
                }
            }
        }

        return response()->json([
            'site' => [
                'id'       => $site->id,
                'name'     => $site->name,
                'altitude' => $site->altitude_m,
                'level'    => $site->level,
            ],
            'scores' => $scores->map(fn ($s) => [
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

    // ────────────────────────────────────────────────────────────

    private function getSunWindow(float $lat, float $lng, string $day): array
    {
        $tz        = new \DateTimeZone('Europe/Paris');
        [$d, $m]   = explode('/', $day);
        $year      = (int) now()->format('Y');
        $ts        = \Carbon\Carbon::createFromDate($year, (int) $m, (int) $d, $tz)
            ->setTime(12, 0)
            ->getTimestamp();

        $sunriseTs = date_sunrise($ts, SUNFUNCS_RET_TIMESTAMP, $lat, $lng, 90.833);
        $sunsetTs  = date_sunset($ts,  SUNFUNCS_RET_TIMESTAMP, $lat, $lng, 90.833);

        // Début : lever - 30min → floor
        $startDt   = (new \DateTime('@' . ($sunriseTs - 1800)))->setTimezone($tz);
        $startHour = (int) $startDt->format('G');

        // Fin : coucher + 30min → ceil
        $endDt   = (new \DateTime('@' . ($sunsetTs + 1800)))->setTimezone($tz);
        $endHour = (int) $endDt->format('G');
        if ((int) $endDt->format('i') > 0) {
            $endHour = min(23, $endHour + 1);
        }

        return [
            'sunrise_display' => (new \DateTime('@' . $sunriseTs))->setTimezone($tz)->format('H:i'),
            'sunset_display'  => (new \DateTime('@' . $sunsetTs))->setTimezone($tz)->format('H:i'),
            'start_hour'      => $startHour,
            'end_hour'        => $endHour,
        ];
    }
}
