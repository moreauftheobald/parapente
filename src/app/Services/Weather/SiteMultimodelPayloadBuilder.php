<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteScore;
use App\Models\WeatherModel;
use App\Services\Map\SunWindowCalculator;
use App\Services\Weather\Apis\ConsensusApi;

/**
 * Construit le payload `/api/sites/{id}/multimodel?day=YYYY-MM-DD&period=…`
 * (panel de comparaison détaillée).
 *
 * Retourne pour chaque heure et chaque modèle actif : vent (min/avg/max/dir),
 * précipitations, humidité, température, plafond — plus le consensus issu
 * des site_scores.
 *
 * Pure : pas de Request, pas d'user-state. Retourne uniquement des
 * arrays/primitives (cf. point 11 CLAUDE.md sur le cache).
 *
 * Extrait de Api\SiteController pour testabilité.
 */
final class SiteMultimodelPayloadBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(Site $site, string $day, string $period): array
    {
        $id       = $site->id;
        $tz       = new \DateTimeZone('Europe/Paris');
        $dayStart = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00', $tz);
        $dayEnd   = (clone $dayStart)->endOfDay();

        $sunWindow = SunWindowCalculator::compute((float) $site->latitude, (float) $site->longitude, $dayStart->format('d/m'));

        $hours = $period === '24h'
            ? range(0, 23)
            : range($sunWindow['start_hour'], $sunWindow['end_hour']);

        // Toujours un array natif (pas une Collection) : on cache le payload
        // et on évite de sérialiser des objets Eloquent (cf. point 11 CLAUDE.md).
        // Le modèle `qui_vole_consensus` est exclu de la liste de comparaison :
        // il EST le consensus (déjà servi dans le bloc `consensus`), pas un
        // modèle NWP à comparer aux autres.
        $modelColors   = config('weather.model_colors', []);
        $fallbackColor = $modelColors['fallback'] ?? '#9ca3af';
        $models        = WeatherModel::where('active', true)
            ->where('code', '!=', ConsensusApi::MODEL_CODE)
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

        $allowedModelIds = array_column($models, 'id');

        $forecasts = Forecast::where('site_id', $id)
            ->whereBetween('forecast_at', [$dayStart, $dayEnd])
            ->get();

        $data = [];
        foreach ($forecasts as $f) {
            $h = (int) $f->forecast_at->format('G');
            if (!in_array($h, $hours, true)) {
                continue;
            }
            // Ignore les lignes du modèle consensus (exclu de $models).
            if (!in_array($f->weather_model_id, $allowedModelIds, true)) {
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
        SiteScore::onActiveBuffer()
            ->where('site_id', $id)
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
}
