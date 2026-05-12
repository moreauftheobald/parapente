<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Models\Site;
use App\Models\SiteScore;
use App\Models\WeatherModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScoringService
{
    // Seuil de convergence : un modèle est "convergent" si sa valeur
    // ne s'écarte pas de plus de N% de la valeur consensus
    private const WIND_DIR_TOLERANCE_DEG   = 30;    // ±30° pour la direction
    private const WIND_SPEED_TOLERANCE_PCT = 25;    // ±25% pour la vitesse
    private const PRECIP_RAIN_THRESHOLD    = 0.1;   // mm/h — en dessous = "pas de pluie"
    private const EPSILON                  = 0.001; // évite division par zéro

    /**
     * Calcule et persiste les scores pour tous les créneaux d'un site.
     */
    public function computeScoresForSite(Site $site): void
    {
        $site->load('conditions');

        if (! $site->conditions) {
            return; // Pas de profil de vol défini
        }

        // Récupère toutes les prévisions futures groupées par créneau
        $forecasts = $site->forecasts()
            ->with('weatherModel')
            ->upcoming()
            ->get()
            ->groupBy(fn ($f) => $f->forecast_at->format('Y-m-d H:i:s'));

        $scores = [];

        foreach ($forecasts as $forecastAt => $modelForecasts) {
            $score = $this->computeScoreForSlot(
                $site,
                $forecastAt,
                $modelForecasts
            );

            if ($score !== null) {
                $scores[] = $score;
            }
        }

        // Upsert en masse — plus efficace qu'un INSERT par créneau
        if (! empty($scores)) {
            SiteScore::upsert(
                $scores,
                ['site_id', 'forecast_at'],
                [
                    'computed_at', 'status', 'confidence_pct',
                    'wind_dir_consensus', 'wind_speed_consensus', 'wind_gust_consensus',
                    'precip_consensus', 'cloud_base_consensus',
                    'models_count', 'models_converging', 'detail',
                ]
            );
        }
    }

    /**
     * Calcule le score d'un site pour un créneau horaire donné.
     */
    private function computeScoreForSlot(
        Site $site,
        string $forecastAt,
        Collection $modelForecasts
    ): ?array {
        $conditions  = $site->conditions;
        $forecastDt  = \Carbon\Carbon::parse($forecastAt);
        $horizonHours = (int) now()->diffInHours($forecastDt);

        // ── 1. Calcul du consensus par variable ─────────────────
        $windDirs   = $this->extractWeighted($modelForecasts, 'wind_direction', $horizonHours);
        $windSpeeds = $this->extractWeighted($modelForecasts, 'wind_speed_avg', $horizonHours);
        $windGusts  = $this->extractWeighted($modelForecasts, 'wind_speed_max', $horizonHours);
        $precips    = $this->extractWeighted($modelForecasts, 'precipitation',  $horizonHours);
        $cloudBases = $this->extractWeighted($modelForecasts, 'cloud_base_m',   $horizonHours);

        if (empty($windDirs) || empty($windSpeeds) || empty($precips)) {
            return null;
        }

        $windDirConsensus   = $this->weightedMeanCircular($windDirs);
        $windSpeedConsensus = $this->weightedMeanInverseSquare($windSpeeds);
        // Rafales : même voting logic. Si aucun modèle ne donne wind_speed_max
        // (cas rare), on retombe sur wind_speed_consensus.
        $windGustConsensus  = !empty($windGusts) ? $this->weightedMeanInverseSquare($windGusts) : $windSpeedConsensus;
        $precipConsensus    = $this->weightedMeanInverseSquare($precips);
        // Plafond de vol estimé : même voting logic (moyenne pondérée inverse
        // carré). null si aucun modèle ne fournit de plafond (ciel dégagé).
        $cloudBaseConsensus = !empty($cloudBases) ? $this->weightedMeanInverseSquare($cloudBases) : null;

        // ── 2. Convergence par variable ──────────────────────────
        $windDirConvergence   = $this->convergenceCircular($windDirs, $windDirConsensus, self::WIND_DIR_TOLERANCE_DEG);
        $windSpeedConvergence = $this->convergenceLinear($windSpeeds, $windSpeedConsensus, self::WIND_SPEED_TOLERANCE_PCT);
        $precipConvergence    = $this->convergencePrecip($precips);

        // ── 3. Règles éliminatoires ──────────────────────────────
        $status = $this->applyEliminatoryRules(
            $conditions,
            $windDirConsensus,
            $windSpeedConsensus,
            $precipConsensus,
            $precipConvergence,
            $modelForecasts
        );

        // ── 4. Confiance globale ─────────────────────────────────
        // Moyenne pondérée des convergences (les éliminatoires pèsent plus)
        $confidencePct = (int) round(
            ($windDirConvergence   * 0.35
           + $windSpeedConvergence * 0.35
           + $precipConvergence    * 0.30) * 100
        );

        // Si orange (modèle dissident sur pluie), on plafonne la confiance
        if ($status === 'orange') {
            $confidencePct = min($confidencePct, 60);
        }

        // ── 5. Modèles convergents ───────────────────────────────
        $modelsCount      = count($windDirs);
        $modelsConverging = (int) round($modelsCount * min($windDirConvergence, $windSpeedConvergence));

        // ── 6. Détail JSON ───────────────────────────────────────
        $detail = [
            'wind_dir' => [
                'consensus'   => $windDirConsensus,
                'convergence' => round($windDirConvergence, 2),
                'values'      => array_column($windDirs, 'value'),
            ],
            'wind_speed' => [
                'consensus'   => $windSpeedConsensus,
                'convergence' => round($windSpeedConvergence, 2),
                'values'      => array_column($windSpeeds, 'value'),
            ],
            'wind_gust' => [
                'consensus' => $windGustConsensus,
                'values'    => array_column($windGusts, 'value'),
            ],
            'precip' => [
                'consensus'   => $precipConsensus,
                'convergence' => round($precipConvergence, 2),
                'values'      => array_column($precips, 'value'),
            ],
            'cloud_base' => [
                'consensus' => $cloudBaseConsensus !== null ? (int) round($cloudBaseConsensus) : null,
                'values'    => array_column($cloudBases, 'value'),
            ],
        ];

        return [
            'site_id'              => $site->id,
            'forecast_at'          => $forecastAt,
            'computed_at'          => now()->toDateTimeString(),
            'status'               => $status,
            'confidence_pct'       => $confidencePct,
            'wind_dir_consensus'   => (int) round($windDirConsensus),
            'wind_speed_consensus' => round($windSpeedConsensus, 1),
            'wind_gust_consensus'  => round($windGustConsensus, 1),
            'precip_consensus'     => round($precipConsensus, 1),
            'cloud_base_consensus' => $cloudBaseConsensus !== null ? (int) round($cloudBaseConsensus) : null,
            'models_count'         => $modelsCount,
            'models_converging'    => $modelsConverging,
            'detail'               => json_encode($detail),
            'created_at'           => now()->toDateTimeString(),
            'updated_at'           => now()->toDateTimeString(),
        ];
    }

    // ── Règles éliminatoires ────────────────────────────────────

    private function applyEliminatoryRules(
        $conditions,
        float $windDir,
        float $windSpeed,
        float $precip,
        float $precipConvergence,
        Collection $modelForecasts
    ): string {
        // Pluie consensus : toujours rouge
        if ($precip > $conditions->precip_max) {
            return 'red';
        }

        // Au moins un modèle prédit de la pluie : orange (prudence)
        $anyRain = $modelForecasts->contains(
            fn ($f) => (float) $f->precipitation > self::PRECIP_RAIN_THRESHOLD
        );
        if ($anyRain) {
            return 'orange';
        }

        // Direction hors plage : rouge
        if (! $conditions->isWindDirectionFavorable((int) $windDir)) {
            return 'red';
        }

        // Vitesse hors plage : rouge
        if (! $conditions->isWindSpeedFavorable($windSpeed)) {
            return 'red';
        }

        // Tout est OK
        return 'green';
    }

    // ── Extraction des valeurs pondérées ────────────────────────

    /**
     * Extrait les valeurs d'une variable avec leur poids selon l'horizon.
     * Retourne : [['value' => float, 'weight' => float], ...]
     */
    private function extractWeighted(
        Collection $forecasts,
        string $field,
        int $horizonHours
    ): array {
        return $forecasts
            ->filter(fn ($f) => $f->$field !== null)
            ->map(fn ($f) => [
                'value'  => (float) $f->$field,
                'weight' => $f->weatherModel->getWeightForHorizon($horizonHours),
            ])
            ->filter(fn ($v) => $v['weight'] > 0)
            ->values()
            ->toArray();
    }

    // ── Moyennes pondérées ──────────────────────────────────────

    /**
     * Moyenne pondérée par inverse du carré de la distance à la médiane.
     * Les outliers sont automatiquement déprioritisés.
     */
    private function weightedMeanInverseSquare(array $items): float
    {
        if (count($items) === 1) {
            return $items[0]['value'];
        }

        // Médiane simple pour référence
        $values = array_column($items, 'value');
        sort($values);
        $median = $values[(int) floor(count($values) / 2)];

        $totalWeight  = 0.0;
        $weightedSum  = 0.0;

        foreach ($items as $item) {
            $distance    = abs($item['value'] - $median);
            $inverseSquare = 1.0 / (($distance ** 2) + self::EPSILON);
            $finalWeight   = $item['weight'] * $inverseSquare;

            $weightedSum  += $item['value'] * $finalWeight;
            $totalWeight  += $finalWeight;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : $median;
    }

    /**
     * Moyenne pondérée circulaire (pour la direction du vent).
     * Convertit en coordonnées cartésiennes pour éviter le problème 359°/1°.
     */
    private function weightedMeanCircular(array $items): float
    {
        $sinSum    = 0.0;
        $cosSum    = 0.0;
        $totalWeight = 0.0;

        foreach ($items as $item) {
            $rad = deg2rad($item['value']);
            $sinSum      += sin($rad) * $item['weight'];
            $cosSum      += cos($rad) * $item['weight'];
            $totalWeight += $item['weight'];
        }

        if ($totalWeight === 0.0) {
            return 0.0;
        }

        $meanRad = atan2($sinSum / $totalWeight, $cosSum / $totalWeight);
        $degrees = rad2deg($meanRad);

        return $degrees < 0 ? $degrees + 360 : $degrees;
    }

    // ── Calculs de convergence ──────────────────────────────────

    /**
     * Convergence sur une variable linéaire.
     * Retourne un ratio 0.0-1.0 basé sur la proportion de modèles
     * dont la valeur est dans la tolérance par rapport au consensus.
     */
    private function convergenceLinear(
        array $items,
        float $consensus,
        float $tolerancePct
    ): float {
        if (empty($items)) {
            return 0.0;
        }

        $totalWeight     = 0.0;
        $convergingWeight = 0.0;
        $tolerance        = $consensus * ($tolerancePct / 100);

        foreach ($items as $item) {
            $totalWeight += $item['weight'];
            if (abs($item['value'] - $consensus) <= $tolerance) {
                $convergingWeight += $item['weight'];
            }
        }

        return $totalWeight > 0 ? $convergingWeight / $totalWeight : 0.0;
    }

    /**
     * Convergence sur la direction du vent (circulaire).
     */
    private function convergenceCircular(
        array $items,
        float $consensus,
        float $toleranceDeg
    ): float {
        if (empty($items)) {
            return 0.0;
        }

        $totalWeight     = 0.0;
        $convergingWeight = 0.0;

        foreach ($items as $item) {
            $diff = abs($item['value'] - $consensus);
            $diff = min($diff, 360 - $diff); // distance circulaire

            $totalWeight += $item['weight'];
            if ($diff <= $toleranceDeg) {
                $convergingWeight += $item['weight'];
            }
        }

        return $totalWeight > 0 ? $convergingWeight / $totalWeight : 0.0;
    }

    /**
     * Convergence sur les précipitations.
     * Logique booléenne : pluie ou pas pluie.
     */
    private function convergencePrecip(array $items): float
    {
        if (empty($items)) {
            return 0.0;
        }

        $totalWeight  = 0.0;
        $dryWeight    = 0.0;

        foreach ($items as $item) {
            $totalWeight += $item['weight'];
            if ($item['value'] <= self::PRECIP_RAIN_THRESHOLD) {
                $dryWeight += $item['weight'];
            }
        }

        return $totalWeight > 0 ? $dryWeight / $totalWeight : 0.0;
    }
}
