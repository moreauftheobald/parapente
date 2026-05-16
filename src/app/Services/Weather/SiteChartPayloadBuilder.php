<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteScore;
use App\Services\Map\SunWindowCalculator;

/**
 * Construit le payload `/api/sites/{id}/chart` (popup graphique de la carte).
 *
 * Pure : pas de Request, pas d'user-state. Retourne uniquement des
 * arrays/primitives (cf. point 11 CLAUDE.md sur le cache).
 *
 * Extrait de Api\SiteController pour testabilité.
 */
final class SiteChartPayloadBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function build(Site $site): array
    {
        $id = $site->id;

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
            $window           = SunWindowCalculator::compute((float) $site->latitude, (float) $site->longitude, $day);
            $sunWindows[$day] = $window;
            $dayData          = [];

            foreach ($dayScores as $score) {
                $h = (int) $score->forecast_at->format('G');
                if ($h < $window['start_hour'] || $h > $window['end_hour']) {
                    continue;
                }

                $key   = $score->forecast_at->format('Y-m-d H:i:s');
                $fcsts = ($rawForecasts[$key] ?? collect())->filter(fn ($f) => $f->wind_speed_avg !== null);

                // Plafonds annoncés par chaque modèle pour ce créneau (min/max).
                $cbVals = $fcsts->pluck('cloud_base_m')->reject(fn ($v) => $v === null);

                // wind_max : on utilise le consensus (voting logic) plutôt que
                // la moyenne arithmétique pour éviter qu'un modèle aberrant
                // ne fausse la rafale affichée.
                $dayData[] = [
                    'hour'           => $score->forecast_at->format('H:i'),
                    'status'         => $score->status,
                    'confidence'     => $score->confidence_pct,
                    'wind_dir'       => $score->wind_dir_consensus,
                    'wind_avg'       => $score->wind_speed_consensus !== null ? round((float) $score->wind_speed_consensus, 1) : null,
                    'wind_min'       => $fcsts->isNotEmpty() ? round($fcsts->avg('wind_speed_min'), 1) : null,
                    'wind_max'       => $score->wind_gust_consensus !== null ? round((float) $score->wind_gust_consensus, 1) : null,
                    'cloud_high'     => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_high')) : null,
                    'cloud_mid'      => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_mid'))  : null,
                    'cloud_low'      => $fcsts->isNotEmpty() ? (int) round($fcsts->avg('cloud_cover_low'))  : null,
                    'cloud_base'     => $score->cloud_base_consensus,
                    'cloud_base_min' => $cbVals->isNotEmpty() ? (int) $cbVals->min() : null,
                    'cloud_base_max' => $cbVals->isNotEmpty() ? (int) $cbVals->max() : null,
                ];
            }

            if (!empty($dayData)) {
                $days[$day] = $dayData;
            }
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
}
