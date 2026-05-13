<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteScore;
use App\Models\WeatherModel;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function index(): JsonResponse
    {
        $sites = Site::active()->with('conditions')->get()->map(fn (Site $site) => [
            'id'           => $site->id,
            'name'         => $site->name,
            'lat'          => (float) $site->latitude,
            'lng'          => (float) $site->longitude,
            'altitude'     => $site->altitude_m,
            'level'        => $site->level,
            'region'       => $site->region,
            // Plage favorable d'orientation du décollage (depuis site_conditions)
            // utilisée par siteIconUrl() pour calculer le bitmask SpotAir.
            'wind_dir_min' => $site->conditions?->wind_dir_min,
            'wind_dir_max' => $site->conditions?->wind_dir_max,
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
        $dayQuality = [];
        foreach ($grouped as $day => $dayScores) {
            $window = $this->getSunWindow((float) $site->latitude, (float) $site->longitude, $day);
            $sunWindows[$day] = $window;
            $byHour = [];
            foreach ($dayScores as $score) {
                $h = (int) $score->forecast_at->format('G');
                if ($h >= $window['start_hour'] && $h <= $window['end_hour']) {
                    $scores->push($score);
                    $byHour[$h] = $score->status;
                }
            }
            $dayQuality[$day] = $this->computeDayQuality($byHour, $window);
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
                'wind_gust'    => $s->wind_gust_consensus,
                'precip'       => $s->precip_consensus,
                'cloud_base'   => $s->cloud_base_consensus,
                'models_count' => $s->models_count,
                'models_conv'  => $s->models_converging,
                // `detail` allégé : pour l'onglet « Détail du scoring », on
                // n'expose que consensus + convergence + color de chaque
                // paramètre (les `values` brutes restent en base mais ne
                // sont pas transférées au client pour limiter le payload).
                'detail'       => $this->trimScoreDetail($s->detail),
            ])->values(),
            'sun_windows' => $sunWindows,
            'day_quality' => $dayQuality,
        ]);
    }

    /**
     * Données horaires agrégées pour le popup graphique :
     * vent min/moy/max, couverture nuageuse haute/moy/basse, direction.
     */
    public function chart(int $id): JsonResponse
    {
        $site = Site::active()->with('conditions')->findOrFail($id);

        // Fenêtre temporelle : du début de la journée courante (pour exposer
        // toute la fenêtre solaire du jour, y compris les heures déjà passées)
        // jusqu'à l'horizon de prévision (5 jours).
        $from = now()->startOfDay();
        $to   = now()->addDays(5);

        // Scores (pour direction consensus + statut)
        $scores = SiteScore::where('site_id', $id)
            ->whereBetween('forecast_at', [$from, $to])
            ->orderBy('forecast_at')
            ->get()
            ->keyBy(fn ($s) => $s->forecast_at->format('Y-m-d H:i:s'));

        // Prévisions brutes agrégées (vent min/max + nuages)
        $rawForecasts = Forecast::where('site_id', $id)
            ->whereBetween('forecast_at', [$from, $to])
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

                // Plafonds annoncés par chaque modèle pour ce créneau (pour min/max)
                $cbVals = $fcsts->pluck('cloud_base_m')->reject(fn ($v) => $v === null);

                // wind_max : on utilise le consensus (voting logic) plutôt que la
                // moyenne arithmétique pour éviter qu'une valeur aberrante d'un
                // modèle ne fausse la rafale affichée.
                $dayData[] = [
                    'hour'       => $score->forecast_at->format('H:i'),
                    'status'     => $score->status,
                    'confidence' => $score->confidence_pct,
                    'wind_dir'   => $score->wind_dir_consensus,
                    'wind_avg'   => $score->wind_speed_consensus !== null ? round((float) $score->wind_speed_consensus, 1) : null,
                    'wind_min'   => $fcsts->isNotEmpty() ? round($fcsts->avg('wind_speed_min'), 1) : null,
                    'wind_max'   => $score->wind_gust_consensus !== null ? round((float) $score->wind_gust_consensus, 1) : null,
                    'cloud_high' => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_high')) : null,
                    'cloud_mid'  => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_mid'))  : null,
                    'cloud_low'  => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_low'))  : null,
                    'cloud_base'     => $score->cloud_base_consensus,
                    'cloud_base_min' => $cbVals->isNotEmpty() ? (int) $cbVals->min() : null,
                    'cloud_base_max' => $cbVals->isNotEmpty() ? (int) $cbVals->max() : null,
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

    /**
     * Données multi-modèles pour le panel de comparaison détaillée.
     *
     * Query params :
     *  - day    : YYYY-MM-DD (obligatoire)
     *  - period : daylight (défaut) | 24h
     *
     * Retourne pour chaque heure et chaque modèle actif :
     * vent (min/avg/max/dir), précipitations, humidité, température.
     * Plus le consensus calculé issu des site_scores.
     */
    public function multimodel(int $id, Request $request): JsonResponse
    {
        $site = Site::active()->with('conditions')->findOrFail($id);

        $day = (string) $request->query('day', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return response()->json(['error' => 'Invalid or missing "day" parameter (YYYY-MM-DD).'], 400);
        }

        $period = $request->query('period', 'daylight');
        if (!in_array($period, ['daylight', '24h'], true)) {
            $period = 'daylight';
        }

        $tz       = new \DateTimeZone('Europe/Paris');
        $dayStart = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00', $tz);
        $dayEnd   = (clone $dayStart)->endOfDay();

        $sunWindow = $this->getSunWindow((float) $site->latitude, (float) $site->longitude, $dayStart->format('d/m'));

        $hours = $period === '24h'
            ? range(0, 23)
            : range($sunWindow['start_hour'], $sunWindow['end_hour']);

        $models = WeatherModel::where('active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (WeatherModel $m) => [
                'id'       => $m->id,
                'code'     => $m->code,
                'name'     => $m->name,
                'provider' => $m->provider,
                'color'    => self::MODEL_COLORS[$m->code] ?? '#9ca3af',
            ])
            ->values();

        $forecasts = Forecast::where('site_id', $id)
            ->whereBetween('forecast_at', [$dayStart, $dayEnd])
            ->get();

        $data = [];
        foreach ($forecasts as $f) {
            $h = (int) $f->forecast_at->format('G');
            if (!in_array($h, $hours, true)) {
                continue;
            }
            $data[$h][$f->weather_model_id] = [
                'wind_min'    => $f->wind_speed_min !== null ? (float) $f->wind_speed_min : null,
                'wind_avg'    => $f->wind_speed_avg !== null ? (float) $f->wind_speed_avg : null,
                'wind_max'    => $f->wind_speed_max !== null ? (float) $f->wind_speed_max : null,
                'wind_dir'    => $f->wind_direction,
                'precip'      => $f->precipitation !== null ? (float) $f->precipitation : null,
                'humidity'    => $f->humidity,
                'temperature' => $f->temperature !== null ? (float) $f->temperature : null,
                'cloud_base'  => $f->cloud_base_m !== null ? (int) $f->cloud_base_m : null,
            ];
        }

        $consensus = [];
        SiteScore::where('site_id', $id)
            ->whereBetween('forecast_at', [$dayStart, $dayEnd])
            ->get()
            ->each(function (SiteScore $s) use (&$consensus, $hours) {
                $h = (int) $s->forecast_at->format('G');
                if (!in_array($h, $hours, true)) {
                    return;
                }
                $consensus[$h] = [
                    'wind_dir'   => $s->wind_dir_consensus,
                    'wind_speed' => $s->wind_speed_consensus !== null ? (float) $s->wind_speed_consensus : null,
                    'wind_gust'  => $s->wind_gust_consensus  !== null ? (float) $s->wind_gust_consensus  : null,
                    'precip'     => $s->precip_consensus !== null ? (float) $s->precip_consensus : null,
                    'cloud_base' => $s->cloud_base_consensus,
                    'status'     => $s->status,
                    'confidence' => $s->confidence_pct,
                ];
            });

        $confidences   = array_filter(array_column($consensus, 'confidence'), fn ($v) => $v !== null);
        $conformityPct = !empty($confidences)
            ? (int) round(array_sum($confidences) / count($confidences))
            : null;

        return response()->json([
            'site' => [
                'id'             => $site->id,
                'name'           => $site->name,
                'altitude'       => $site->altitude_m,
                'lat'            => (float) $site->latitude,
                'lng'            => (float) $site->longitude,
                'level'          => $site->level,
                'wind_dir_min'   => $site->conditions?->wind_dir_min,
                'wind_dir_max'   => $site->conditions?->wind_dir_max,
                'wind_speed_min' => $site->conditions?->wind_speed_min !== null ? (float) $site->conditions->wind_speed_min : null,
                'wind_speed_max' => $site->conditions?->wind_speed_max !== null ? (float) $site->conditions->wind_speed_max : null,
            ],
            'day'            => $day,
            'period'         => $period,
            'hours'          => $hours,
            'sun_window'     => $sunWindow,
            'models'         => $models,
            'data'           => $data,
            'consensus'      => $consensus,
            'conformity_pct' => $conformityPct,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────
    //
    // Viabilité d'une journée : on pondère chaque heure de la fenêtre
    // solaire par (poids horaire × valeur du statut × facteur de
    // continuité). Tous les seuils sont modifiables dans /admin/settings
    // (clés `viability.*`).

    /**
     * Calcule la qualité d'une journée (viabilité 0-100 + statut dérivé)
     * à partir des statuts horaires dans la fenêtre solaire.
     *
     * @param  array<int,string> $byHour  heure (0-23) => 'green'|'orange'|'red'
     * @param  array{start_hour:int,end_hour:int} $window
     * @return array{viability:int,status:string,green_hours:int}
     */
    private function computeDayQuality(array $byHour, array $window): array
    {
        $start = (int) $window['start_hour'];
        $end   = (int) $window['end_hour'];
        if ($end < $start) {
            return ['viability' => 0, 'status' => 'unknown', 'green_hours' => 0];
        }

        // Paramètres lus depuis la table `settings` (admin /admin/settings)
        $peakHour    = (float) $this->settings->get('viability.peak_hour');
        $sigma       = (float) $this->settings->get('viability.sigma');
        $valGreen    = (float) $this->settings->get('viability.val_green');
        $valOrange   = (float) $this->settings->get('viability.val_orange');
        $runBase     = (float) $this->settings->get('viability.run_base');
        $runStep     = (float) $this->settings->get('viability.run_step');
        $thrGreen    = (int)   $this->settings->get('viability.green_threshold');
        $thrOrange   = (int)   $this->settings->get('viability.orange_threshold');

        $timeWeight = fn (int $h): float => exp(-(($h - $peakHour) ** 2) / (2 * $sigma ** 2));
        $statusVal  = fn (?string $s): float => match ($s) {
            'green'  => $valGreen,
            'orange' => $valOrange,
            default  => 0.0,
        };

        // Longueur du run de créneaux "volables" (green|orange) auquel appartient chaque heure.
        $runLen = [];
        for ($h = $start; $h <= $end; $h++) {
            $flyable = in_array($byHour[$h] ?? null, ['green', 'orange'], true);
            $runLen[$h] = $flyable ? (($runLen[$h - 1] ?? 0) + 1) : 0;
        }
        for ($h = $end - 1; $h >= $start; $h--) {
            if ($runLen[$h] > 0 && ($runLen[$h + 1] ?? 0) > $runLen[$h]) {
                $runLen[$h] = $runLen[$h + 1];
            }
        }
        $runFactor = fn (int $len): float => $len <= 0 ? 0.0 : min(1.0, $runBase + $runStep * ($len - 1));

        $num = 0.0;
        $den = 0.0;
        $greenHours = 0;
        $hasData = false;
        for ($h = $start; $h <= $end; $h++) {
            $w = $timeWeight($h);
            $den += $w;
            $st = $byHour[$h] ?? null;
            if ($st !== null) {
                $hasData = true;
            }
            if ($st === 'green') {
                $greenHours++;
            }
            $num += $w * $statusVal($st) * $runFactor($runLen[$h]);
        }

        $viability = $den > 0.0 ? (int) round(100 * $num / $den) : 0;
        $status = ! $hasData
            ? 'unknown'
            : ($viability >= $thrGreen
                ? 'green'
                : ($viability >= $thrOrange ? 'orange' : 'red'));

        return ['viability' => $viability, 'status' => $status, 'green_hours' => $greenHours];
    }

    /**
     * Couleurs des 10 modèles météo (palette HCL distincte).
     * Code modèle Open-Meteo => couleur hex.
     */
    private const MODEL_COLORS = [
        'meteofrance_arome_france'   => '#ef4444',
        'icon_d2'                    => '#a855f7',
        'knmi_harmonie_arome_europe' => '#06b6d4',
        'meteofrance_arpege_europe'  => '#f59e0b',
        'icon_eu'                    => '#3b82f6',
        'ecmwf_ifs025'               => '#8b5cf6',
        'ecmwf_aifs025'              => '#ec4899',
        'icon_seamless'              => '#84cc16',
        'gem_seamless'               => '#10b981',
        'gfs_seamless'               => '#f97316',
    ];

    /**
     * Allège le JSON `detail` d'un SiteScore : on conserve consensus,
     * convergence (si présente) et color de chaque paramètre, on retire les
     * arrays `values` (utiles seulement au backfill côté serveur).
     */
    private function trimScoreDetail(?array $detail): ?array
    {
        if (! is_array($detail)) {
            return null;
        }
        $out = [];
        foreach ($detail as $param => $info) {
            if (! is_array($info)) continue;
            $entry = ['consensus' => $info['consensus'] ?? null];
            if (array_key_exists('convergence', $info)) $entry['convergence'] = $info['convergence'];
            if (array_key_exists('color', $info))       $entry['color']       = $info['color'];
            $out[$param] = $entry;
        }
        return $out;
    }

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
