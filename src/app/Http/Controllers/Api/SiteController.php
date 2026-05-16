<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteScore;
use App\Models\UserSiteCondition;
use App\Models\WeatherModel;
use App\Services\Map\SiteDetailCache;
use App\Services\Settings;
use App\Services\Weather\UserScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(
        private Settings $settings,
        private UserScoringService $userScoring,
        private SiteDetailCache $detailCache,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $userId       = $request->user()?->id;
        $userScorings = $userId !== null
            ? UserSiteCondition::forUser($userId)
                ->get(['site_id', 'is_active'])
                ->keyBy('site_id')
            : collect();

        $sites = Site::active()->with('conditions')->get()->map(function (Site $site) use ($userScorings) {
            $usc = $userScorings->get($site->id);

            return [
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
                // Présence et état du scoring perso de l'utilisateur sur ce site.
                // null = pas de scoring perso enregistré ; 'active' / 'inactive'
                // = badge à afficher sur le marqueur (cf. FF_personnal_scoring).
                'user_scoring' => $usc === null
                    ? null
                    : ((bool) $usc->is_active ? 'active' : 'inactive'),
            ];
        });
        return response()->json($sites);
    }

    /**
     * Scores horaires + sun windows + day quality d'un site.
     *
     * Le payload "global" (sans rescore perso) est mis en cache Redis
     * via SiteDetailCache (TTL 90 min, invalidé par ScoreSiteJob). Si
     * l'utilisateur a un scoring perso ACTIF sur ce site, on lit le
     * cache puis on applique le rescore par-dessus (status, couleurs,
     * day_quality). Sinon on renvoie le cache tel quel — cas dominant.
     */
    public function scores(int $id, Request $request): JsonResponse
    {
        $site = Site::active()->findOrFail($id);

        // Récupère/construit la version "global" depuis le cache.
        // Le builder fait toutes les queries SQL et la mise en forme.
        $payload = $this->detailCache->rememberScores(
            $site->id,
            fn () => $this->buildGlobalScoresPayload($site),
        );

        // Si user authentifié avec scoring perso ACTIF sur ce site,
        // applique le rescore. Le rescore est lui-même cached 1 h par
        // UserScoringService (très rapide en cache hit).
        $userId       = $request->user()?->id;
        $userRescored = $userId !== null
            ? $this->userScoring->rescoreForUserSite($userId, $site->id)
            : null;

        if ($userRescored !== null) {
            $payload = $this->applyUserRescoreToPayload($payload, $userRescored, $site);
        }

        return response()->json($payload);
    }

    /**
     * Construit le payload "global" (sans rescore perso) des scores
     * d'un site. Appelé par le cache miss de scores() — doit donc
     * être pur (pas de Request, pas d'user-state) et retourner des
     * arrays/primitives uniquement (pas d'Eloquent).
     *
     * @return array<string,mixed>
     */
    private function buildGlobalScoresPayload(Site $site): array
    {
        $allScores = SiteScore::where('site_id', $site->id)
            ->upcoming()
            ->orderBy('forecast_at')
            ->get();

        $grouped    = $allScores->groupBy(fn ($s) => $s->forecast_at->format('d/m'));
        $scores     = collect();
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

        return [
            'site'           => ['id' => $site->id, 'name' => $site->name, 'altitude' => $site->altitude_m, 'level' => $site->level],
            'scoring_source' => 'global',
            'scores'         => $scores->map(function ($s) {
                return [
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
                    'detail'       => $this->trimScoreDetail($s->detail, null),
                ];
            })->values()->all(),
            'sun_windows' => $sunWindows,
            'day_quality' => $dayQuality,
        ];
    }

    /**
     * Applique le rescore perso d'un utilisateur sur un payload global
     * (issu du cache). Modifie : `scoring_source`, `status` + couleurs
     * par créneau, `status_global` (préservé), et recalcule
     * `day_quality` avec les nouveaux statuses.
     *
     * @param array<string,mixed> $payload
     * @param array<string,array{status:string,colors:array<string,string>}> $userRescored
     * @return array<string,mixed>
     */
    private function applyUserRescoreToPayload(array $payload, array $userRescored, Site $site): array
    {
        $payload['scoring_source'] = 'user';

        // 1. Reconstruit la map by-day des statuses pour recalculer day_quality.
        // Le payload cached n'a pas les forecast_at H:i:s — on les reconstruit
        // depuis day (d/m) + hour (H:i) en assumant l'année courante.
        $byDay = [];
        $now   = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

        // 2. Met à jour chaque score : status user + status_global + couleurs.
        foreach ($payload['scores'] as &$score) {
            // Reconstitue le forecast_at Y-m-d H:i:s à partir de day d/m + hour H:i
            // (le payload a forecast_at en Y-m-d H:i, on le complète).
            $rkey  = $score['forecast_at'] . ':00';
            $perso = $userRescored[$rkey] ?? null;
            if ($perso === null) {
                continue;
            }
            $score['status_global'] = $score['status'];
            $score['status']        = $perso['status'];

            // Met à jour les couleurs dans le `detail.<param>.color`
            // (préserve color_global = ancienne couleur globale).
            if (isset($score['detail']) && is_array($score['detail'])) {
                foreach ($score['detail'] as $param => &$info) {
                    if (! is_array($info)) {
                        continue;
                    }
                    $userColor = $perso['colors'][$param] ?? null;
                    if ($userColor !== null) {
                        $info['color_global'] = $info['color'] ?? null;
                        $info['color']        = $userColor;
                    }
                }
                unset($info);
            }

            // Indexe par jour pour recalcul day_quality
            $day = $score['day'];
            $h   = (int) explode(':', $score['hour'])[0];
            $byDay[$day][$h] = $perso['status'];
        }
        unset($score);

        // 3. Recalcule day_quality avec les statuses user.
        foreach ($payload['sun_windows'] as $day => $window) {
            $statuses = $byDay[$day] ?? [];
            if (empty($statuses)) {
                // Pas d'override sur ce jour → conserver le day_quality global.
                continue;
            }
            $payload['day_quality'][$day] = $this->computeDayQuality($statuses, $window);
        }

        return $payload;
    }

    /**
     * Données horaires agrégées pour le popup graphique :
     * vent min/moy/max, couverture nuageuse haute/moy/basse, direction.
     *
     * Mis en cache transparent (TTL 90 min, invalidé par ScoreSiteJob).
     */
    public function chart(int $id): JsonResponse
    {
        $site = Site::active()->with('conditions')->findOrFail($id);

        $payload = $this->detailCache->rememberChart(
            $site->id,
            fn () => $this->buildChartPayload($site),
        );

        return response()->json($payload);
    }

    /**
     * Construction pure du payload chart (réutilisée par le cache miss).
     *
     * @return array<string,mixed>
     */
    private function buildChartPayload(Site $site): array
    {
        $id   = $site->id;
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

        return [
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
        ];
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

        // Cache transparent : pas de logique user-spec, on stocke
        // directement le payload complet (TTL 90 min, invalidé à la fin
        // du ScoreSiteJob via SiteDetailCache::forgetSite).
        $payload = $this->detailCache->rememberMultimodel(
            $site->id,
            $day,
            $period,
            fn () => $this->buildMultimodelPayload($site, $day, $period),
        );

        return response()->json($payload);
    }

    /**
     * Construction pure du payload multimodel (réutilisée par le cache miss).
     *
     * @return array<string,mixed>
     */
    private function buildMultimodelPayload(Site $site, string $day, string $period): array
    {
        $id       = $site->id;
        $tz       = new \DateTimeZone('Europe/Paris');
        $dayStart = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00', $tz);
        $dayEnd   = (clone $dayStart)->endOfDay();

        $sunWindow = $this->getSunWindow((float) $site->latitude, (float) $site->longitude, $dayStart->format('d/m'));

        $hours = $period === '24h'
            ? range(0, 23)
            : range($sunWindow['start_hour'], $sunWindow['end_hour']);

        // Toujours un array natif (pas une Collection) : on cache le payload
        // et on évite de sérialiser des objets Eloquent (cf. point 11 CLAUDE.md).
        $modelColors    = config('weather.model_colors', []);
        $fallbackColor  = $modelColors['fallback'] ?? '#9ca3af';
        $models         = WeatherModel::where('active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (WeatherModel $m) => [
                'id'       => $m->id,
                'code'     => $m->code,
                'name'     => $m->name,
                'provider' => $m->provider,
                'color'    => $modelColors[$m->code] ?? $fallbackColor,
            ])
            ->values()
            ->all();

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

        return [
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
        ];
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
     * Allège le JSON `detail` d'un SiteScore : on conserve consensus,
     * convergence (si présente) et color de chaque paramètre, on retire les
     * arrays `values` (utiles seulement au backfill côté serveur).
     *
     * Si `$userColors` est passé (couleurs recalculées depuis un scoring
     * perso actif), `color` reçoit la version perso, et la version globale
     * est conservée dans `color_global` — sert à l'onglet comparaison
     * (cellule split diagonale, cf. FF_personnal_scoring.md).
     *
     * @param array<string,string>|null $userColors
     */
    private function trimScoreDetail(?array $detail, ?array $userColors = null): ?array
    {
        if (! is_array($detail)) {
            return null;
        }
        $out = [];
        foreach ($detail as $param => $info) {
            if (! is_array($info)) continue;
            $entry = ['consensus' => $info['consensus'] ?? null];
            if (array_key_exists('convergence', $info)) {
                $entry['convergence'] = $info['convergence'];
            }
            $globalColor = $info['color'] ?? null;
            if ($userColors !== null && array_key_exists($param, $userColors)) {
                $entry['color']        = $userColors[$param];
                $entry['color_global'] = $globalColor;
            } elseif ($globalColor !== null) {
                $entry['color'] = $globalColor;
            }
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
