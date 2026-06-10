<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\Balise;
use App\Models\BaliseReadingHourly;
use App\Models\WeatherModel;
use App\Models\WeatherStation;
use App\Models\WeatherStationObservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Construit les séries « mesures vs consensus » J−2 → J+2 pour une balise
 * (et plus tard une station), exposées par l'onglet « Évolution » de la
 * popup carte.
 *
 * Axe : 5 jours × 8 pas de 3 h = 40 points (J−2 00h → J+2 21h, Europe/Paris).
 *  - `measure`   : relevés agrégés en pas de 3 h (passé jusqu'à maintenant) ;
 *                  source `balise_readings_hourly` (rétention 30 j).
 *  - `consensus` : LU dans `forecast_archive_balises` comme le modèle
 *                  `qui_vole_consensus` (écrit par le job d'archivage,
 *                  source unique = sidecar). Agrégé par pas de 3 h.
 *
 * Le consensus n'est PAS recalculé ici : une seule source de vérité, le
 * sidecar (archivé heure par heure par les jobs Fetch*ForecastsJob).
 */
final class ComparisonSeriesBuilder
{
    private const STEPS = 40;        // 5 j × 8 pas
    private const STEP_HOURS = 3;    // pas de 3 h
    private const DAYS_BACK = 2;     // J−2 = début de l'axe

    /** @return array<string,mixed> */
    public function forBalise(Balise $balise): array
    {
        $tz  = new \DateTimeZone('Europe/Paris');
        $now = Carbon::now($tz);
        $axisStart = $now->copy()->startOfDay()->subDays(self::DAYS_BACK);
        $axisEnd   = $axisStart->copy()->addHours(self::STEPS * self::STEP_HOURS);
        $nowStep   = max(0, min(self::STEPS - 1, intdiv((int) $axisStart->diffInMinutes($now), self::STEP_HOURS * 60)));

        $bucketOf = fn (Carbon $t): int => intdiv((int) $axisStart->diffInMinutes($t), self::STEP_HOURS * 60);

        // ── MESURES (balise_readings_hourly) ────────────────────────────
        $hourly = BaliseReadingHourly::where('balise_id', $balise->id)
            ->where('hour_at', '>=', $axisStart)
            ->where('hour_at', '<', $axisEnd)
            ->orderBy('hour_at')
            ->get(['hour_at', 'wind_direction', 'wind_speed_avg', 'wind_speed_max']);

        $mAvg = array_fill(0, self::STEPS, []);
        $mMax = array_fill(0, self::STEPS, []);
        $mDir = array_fill(0, self::STEPS, []);
        foreach ($hourly as $r) {
            $b = $bucketOf($r->hour_at);
            if ($b < 0 || $b >= self::STEPS) continue;
            if ($r->wind_speed_avg !== null) $mAvg[$b][] = (float) $r->wind_speed_avg;
            if ($r->wind_speed_max !== null) $mMax[$b][] = (float) $r->wind_speed_max;
            if ($r->wind_direction !== null) $mDir[$b][] = (float) $r->wind_direction;
        }

        // ── CONSENSUS (lu, PAS recalculé) ───────────────────────────────
        // Le consensus est écrit par le job d'archivage comme le modèle
        // `qui_vole_consensus` (source unique : sidecar). On lit le dernier
        // run par créneau, agrégé par pas de 3 h.
        $cAvg = array_fill(0, self::STEPS, []);
        $cMax = array_fill(0, self::STEPS, []);
        $cDir = array_fill(0, self::STEPS, []);
        $consensusId = WeatherModel::where('code', WeatherModel::CONSENSUS_CODE)->value('id');
        if ($consensusId !== null) {
            $rows = DB::table('forecast_archive_balises')
                ->where('balise_id', $balise->id)
                ->where('weather_model_id', $consensusId)
                ->where('target_at', '>=', $axisStart)
                ->where('target_at', '<', $axisEnd)
                ->orderBy('fetched_at')
                ->get(['target_at', 'wind_direction', 'wind_speed_avg', 'wind_speed_max']);

            $latest = [];
            foreach ($rows as $row) {
                $latest[$row->target_at] = $row; // ordonné par fetched_at → dernier run gagne
            }
            foreach ($latest as $row) {
                $b = $bucketOf(Carbon::parse($row->target_at, $tz));
                if ($b < 0 || $b >= self::STEPS) continue;
                if ($row->wind_speed_avg !== null) $cAvg[$b][] = (float) $row->wind_speed_avg;
                if ($row->wind_speed_max !== null) $cMax[$b][] = (float) $row->wind_speed_max;
                if ($row->wind_direction !== null) $cDir[$b][] = (float) $row->wind_direction;
            }
        }

        // ── Assemblage des séries ───────────────────────────────────────
        $idx = range(0, self::STEPS - 1);
        $mean = fn (array $a): ?float => $a === [] ? null : round(array_sum($a) / count($a), 1);
        $peak = fn (array $a): ?float => $a === [] ? null : round(max($a), 1);

        // measure : passé seulement (≤ nowStep) ; consensus : tout l'axe.
        $measureAvg  = array_map(fn ($i) => $i <= $nowStep ? $mean($mAvg[$i]) : null, $idx);
        $measureGust = array_map(fn ($i) => $i <= $nowStep ? $peak($mMax[$i]) : null, $idx); // pic mesuré sur 3 h
        $measureDir  = array_map(fn ($i) => $i <= $nowStep ? $this->circularMean($mDir[$i]) : null, $idx);
        $consAvg  = array_map(fn ($i) => $mean($cAvg[$i]), $idx);
        $consGust = array_map(fn ($i) => $mean($cMax[$i]), $idx);
        $consDir  = array_map(fn ($i) => $this->circularMean($cDir[$i]), $idx);

        $days = [];
        for ($d = 0; $d < 5; $d++) {
            $dt = $axisStart->copy()->addDays($d);
            $days[] = [
                'raw'   => $dt->format('d/m'),
                'label' => $d === self::DAYS_BACK ? 'Aujourd\'hui'
                    : ($d < self::DAYS_BACK ? 'J−' . (self::DAYS_BACK - $d) : 'J+' . ($d - self::DAYS_BACK)),
            ];
        }

        return [
            'now_step' => $nowStep,
            'now_idx'  => self::DAYS_BACK,
            'days'     => $days,
            'vars'     => [
                'wind_mean' => $this->var('km/h', 0, $this->niceMax($measureAvg, $consAvg, 15), $measureAvg, $consAvg, '#4ade80'),
                'wind_gust' => $this->var('km/h', 0, $this->niceMax($measureGust, $consGust, 20), $measureGust, $consGust, '#fbbf24'),
                'wind_dir'  => $this->var('°', 0, 360, $measureDir, $consDir, '#4ea8e0'),
            ],
        ];
    }

    /**
     * Idem forBalise pour une station météo. Mesures depuis
     * `weather_station_observations` ; consensus depuis
     * `forecast_archive_stations` (modèle qui_vole_consensus). Variables :
     * vent moyen / rafales / température / humidité / pression.
     *
     * @return array<string,mixed>
     */
    public function forStation(WeatherStation $station): array
    {
        $tz  = new \DateTimeZone('Europe/Paris');
        $now = Carbon::now($tz);
        $axisStart = $now->copy()->startOfDay()->subDays(self::DAYS_BACK);
        $axisEnd   = $axisStart->copy()->addHours(self::STEPS * self::STEP_HOURS);
        $nowStep   = max(0, min(self::STEPS - 1, intdiv((int) $axisStart->diffInMinutes($now), self::STEP_HOURS * 60)));
        $bucketOf  = fn (Carbon $t): int => intdiv((int) $axisStart->diffInMinutes($t), self::STEP_HOURS * 60);

        // ── MESURES (weather_station_observations) ──────────────────────
        $obs = WeatherStationObservation::where('weather_station_id', $station->id)
            ->where('observed_at', '>=', $axisStart)
            ->where('observed_at', '<', $axisEnd)
            ->orderBy('observed_at')
            ->get(['observed_at', 'wind_speed_avg', 'wind_speed_max', 'temperature', 'humidity', 'pressure_hpa']);

        $fields = ['wind_speed_avg', 'wind_speed_max', 'temperature', 'humidity', 'pressure_hpa'];
        $m = [];
        foreach ($fields as $f) $m[$f] = array_fill(0, self::STEPS, []);
        foreach ($obs as $o) {
            $b = $bucketOf($o->observed_at);
            if ($b < 0 || $b >= self::STEPS) continue;
            foreach ($fields as $f) if ($o->{$f} !== null) $m[$f][$b][] = (float) $o->{$f};
        }

        // ── CONSENSUS (forecast_archive_stations, qui_vole_consensus) ────
        $c = [];
        foreach ($fields as $f) $c[$f] = array_fill(0, self::STEPS, []);
        $consensusId = WeatherModel::where('code', WeatherModel::CONSENSUS_CODE)->value('id');
        if ($consensusId !== null) {
            $rows = DB::table('forecast_archive_stations')
                ->where('weather_station_id', $station->id)
                ->where('weather_model_id', $consensusId)
                ->where('target_at', '>=', $axisStart)
                ->where('target_at', '<', $axisEnd)
                ->orderBy('fetched_at')
                ->get(['target_at', 'wind_speed_avg', 'wind_speed_max', 'temperature', 'humidity', 'pressure_hpa']);
            $latest = [];
            foreach ($rows as $row) $latest[$row->target_at] = $row;
            foreach ($latest as $row) {
                $b = $bucketOf(Carbon::parse($row->target_at, $tz));
                if ($b < 0 || $b >= self::STEPS) continue;
                foreach ($fields as $f) if ($row->{$f} !== null) $c[$f][$b][] = (float) $row->{$f};
            }
        }

        $idx  = range(0, self::STEPS - 1);
        $mean = fn (array $a): ?float => $a === [] ? null : round(array_sum($a) / count($a), 1);
        $peak = fn (array $a): ?float => $a === [] ? null : round(max($a), 1);

        // measure : passé seulement ; consensus : tout l'axe
        $mPast = fn (callable $agg, string $f) => array_map(fn ($i) => $i <= $nowStep ? $agg($m[$f][$i]) : null, $idx);
        $cAll  = fn (callable $agg, string $f) => array_map(fn ($i) => $agg($c[$f][$i]), $idx);

        $measAvg = $mPast($mean, 'wind_speed_avg'); $consAvg = $cAll($mean, 'wind_speed_avg');
        $measGus = $mPast($peak, 'wind_speed_max'); $consGus = $cAll($mean, 'wind_speed_max');
        $measTmp = $mPast($mean, 'temperature');    $consTmp = $cAll($mean, 'temperature');
        $measHum = $mPast($mean, 'humidity');        $consHum = $cAll($mean, 'humidity');
        $measPrs = $mPast($mean, 'pressure_hpa');    $consPrs = $cAll($mean, 'pressure_hpa');

        [$tMin, $tMax] = $this->niceRange($measTmp, $consTmp, 2, 2);
        [$pMin, $pMax] = $this->niceRange($measPrs, $consPrs, 3, 5);

        $days = [];
        for ($d = 0; $d < 5; $d++) {
            $dt = $axisStart->copy()->addDays($d);
            $days[] = [
                'raw'   => $dt->format('d/m'),
                'label' => $d === self::DAYS_BACK ? 'Aujourd\'hui'
                    : ($d < self::DAYS_BACK ? 'J−' . (self::DAYS_BACK - $d) : 'J+' . ($d - self::DAYS_BACK)),
            ];
        }

        return [
            'now_step' => $nowStep,
            'now_idx'  => self::DAYS_BACK,
            'days'     => $days,
            'vars'     => [
                'wind_mean' => $this->var('km/h', 0, $this->niceMax($measAvg, $consAvg, 15), $measAvg, $consAvg, '#4ade80'),
                'wind_gust' => $this->var('km/h', 0, $this->niceMax($measGus, $consGus, 20), $measGus, $consGus, '#fbbf24'),
                'temp'      => $this->var('°C',  $tMin, $tMax, $measTmp, $consTmp, '#f97316'),
                'humidity'  => $this->var('%',   0, 100, $measHum, $consHum, '#4ea8e0'),
                'pressure'  => $this->var('hPa', $pMin, $pMax, $measPrs, $consPrs, '#a78bfa'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function var(string $unit, float $min, float $max, array $measure, array $consensus, string $color): array
    {
        return compact('unit', 'min', 'max', 'measure', 'consensus', 'color');
    }

    /**
     * Bornes Y « rondes » couvrant mesures + consensus (température,
     * pression). @return array{0:float,1:float}
     */
    private function niceRange(array $a, array $b, float $pad, float $step): array
    {
        $vals = array_filter(array_merge($a, $b), fn ($v) => $v !== null);
        if ($vals === []) return [0.0, $step * 5];
        $mn = min($vals); $mx = max($vals);
        return [floor(($mn - $pad) / $step) * $step, ceil(($mx + $pad) / $step) * $step];
    }

    /** Plafond Y « rond » (multiple de 5) couvrant mesures + consensus. */
    private function niceMax(array $a, array $b, int $floor): int
    {
        $vals = array_filter(array_merge($a, $b), fn ($v) => $v !== null);
        $peak = $vals === [] ? 0 : max($vals);
        return max($floor, (int) (ceil($peak / 5) * 5));
    }

    /** Moyenne circulaire (degrés) ; null si vide. */
    private function circularMean(array $degs): ?float
    {
        if ($degs === []) return null;
        $sx = 0.0; $sy = 0.0;
        foreach ($degs as $d) { $r = deg2rad($d); $sx += cos($r); $sy += sin($r); }
        $a = rad2deg(atan2($sy / count($degs), $sx / count($degs)));
        return round(fmod($a + 360, 360), 1);
    }
}
