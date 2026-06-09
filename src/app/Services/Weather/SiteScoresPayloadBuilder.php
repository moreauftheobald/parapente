<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\Site;
use App\Models\SiteScore;
use App\Services\Map\DayQualityCalculator;
use App\Services\Map\SunWindowCalculator;

/**
 * Construit le payload `/api/sites/{id}/scores`.
 *
 * Deux entrées :
 *  - buildGlobal()        : version "globale" mise en cache Redis. Pure
 *                           (pas de Request, pas d'user-state) — ne renvoie
 *                           que des arrays/primitives (cf. point 11 CLAUDE.md).
 *  - applyUserRescore()   : applique par-dessus un rescore perso utilisateur
 *                           (status + couleurs + recalcul day_quality).
 *
 * Extrait de Api\SiteController pour testabilité.
 */
final class SiteScoresPayloadBuilder
{
    public function __construct(
        private readonly DayQualityCalculator $dayQuality,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildGlobal(Site $site): array
    {
        $allScores = SiteScore::onActiveBuffer()
            ->where('site_id', $site->id)
            ->upcoming()
            ->orderBy('forecast_at')
            ->get();

        $grouped    = $allScores->groupBy(fn ($s) => $s->forecast_at->format('d/m'));
        $scores     = collect();
        $sunWindows = [];
        $dayQuality = [];

        foreach ($grouped as $day => $dayScores) {
            $window = SunWindowCalculator::compute((float) $site->latitude, (float) $site->longitude, $day);
            $sunWindows[$day] = $window;
            $byHour = [];
            foreach ($dayScores as $score) {
                $h = (int) $score->forecast_at->format('G');
                if ($h >= $window['start_hour'] && $h <= $window['end_hour']) {
                    $scores->push($score);
                    $byHour[$h] = $score->status;
                }
            }
            $dayQuality[$day] = $this->dayQuality->compute($byHour, $window);
        }

        return [
            'site'           => ['id' => $site->id, 'name' => $site->name, 'altitude' => $site->altitude_m, 'level' => $site->level],
            'scoring_source' => 'global',
            'scores'         => $scores->map(fn ($s) => [
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
                'detail'       => self::trimScoreDetail($s->detail),
            ])->values()->all(),
            'sun_windows' => $sunWindows,
            'day_quality' => $dayQuality,
        ];
    }

    /**
     * Applique le rescore perso d'un utilisateur sur un payload global
     * (issu du cache). Modifie : `scoring_source`, `status` + couleurs par
     * créneau, `status_global` (préservé), et recalcule `day_quality` avec
     * les nouveaux statuses.
     *
     * @param array<string,mixed> $payload
     * @param array<string,array{status:string,colors:array<string,string>}> $userRescored
     * @return array<string,mixed>
     */
    public function applyUserRescore(array $payload, array $userRescored): array
    {
        $payload['scoring_source'] = 'user';

        // Reconstruit la map by-day des statuses pour recalculer day_quality.
        $byDay = [];

        foreach ($payload['scores'] as &$score) {
            // Le payload a forecast_at en Y-m-d H:i, on le complète en :00
            // pour matcher la clé du rescore (Y-m-d H:i:s).
            $rkey  = $score['forecast_at'] . ':00';
            $perso = $userRescored[$rkey] ?? null;
            if ($perso === null) {
                continue;
            }
            $score['status_global'] = $score['status'];
            $score['status']        = $perso['status'];

            // Met à jour les couleurs dans `detail.<param>.color` (préserve
            // color_global = ancienne couleur globale).
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

            $day = $score['day'];
            $h   = (int) explode(':', $score['hour'])[0];
            $byDay[$day][$h] = $perso['status'];
        }
        unset($score);

        // Recalcule day_quality avec les statuses user.
        foreach ($payload['sun_windows'] as $day => $window) {
            $statuses = $byDay[$day] ?? [];
            if (empty($statuses)) {
                // Pas d'override sur ce jour → conserver le day_quality global.
                continue;
            }
            $payload['day_quality'][$day] = $this->dayQuality->compute($statuses, $window);
        }

        return $payload;
    }

    /**
     * Allège le JSON `detail` d'un SiteScore : conserve consensus,
     * convergence (si présente) et color de chaque paramètre ; retire les
     * arrays `values` (utiles seulement au backfill côté serveur).
     */
    private static function trimScoreDetail(?array $detail): ?array
    {
        if (! is_array($detail)) {
            return null;
        }
        $out = [];
        foreach ($detail as $param => $info) {
            if (! is_array($info)) {
                continue;
            }
            $entry = ['consensus' => $info['consensus'] ?? null];
            if (array_key_exists('convergence', $info)) {
                $entry['convergence'] = $info['convergence'];
            }
            if (($info['color'] ?? null) !== null) {
                $entry['color'] = $info['color'];
            }
            $out[$param] = $entry;
        }
        return $out;
    }
}
