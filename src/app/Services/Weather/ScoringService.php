<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Contracts\FlyingConditions;
use App\Models\Forecast;
use App\Models\Site;
use App\Models\SiteScore;
use App\Models\WeatherModel;
use App\Services\Settings;
use App\Services\Weather\Apis\ConsensusApi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScoringService
{
    // Seuil de convergence : un modèle est "convergent" si sa valeur
    // ne s'écarte pas de plus de N% de la valeur consensus
    private const WIND_DIR_TOLERANCE_DEG   = 30;    // ±30° pour la direction
    private const WIND_SPEED_TOLERANCE_PCT = 25;    // ±25% pour la vitesse
    private const PRECIP_RAIN_THRESHOLD    = 0.1;   // mm/h — seuil "trace de pluie" (convergence)
    private const EPSILON                  = 0.001; // évite division par zéro

    // Âge max d'une prévision `qui_vole_consensus` pour être considérée
    // « fraîche ». Au-delà (sidecar tombé, run manqué), on retombe sur le
    // calcul de consensus interne à partir des autres modèles.
    private const CONSENSUS_MAX_AGE_MINUTES = 120;

    // Seuils globaux préchargés à la construction (au lieu d'appeler
    // Settings::get() N fois par scoring — typiquement 120 créneaux × 6 lookups).
    private float $precipOrangeMmh;
    private float $precipRedMmh;
    private float $gustOrangeDefault;
    private float $gustRedDefault;

    public function __construct(private Settings $settings)
    {
        $this->precipOrangeMmh   = (float) $settings->get('scoring.precip_orange_mmh');
        $this->precipRedMmh      = (float) $settings->get('scoring.precip_red_mmh');
        $this->gustOrangeDefault = (float) $settings->get('scoring.gust_orange_kmh');
        $this->gustRedDefault    = (float) $settings->get('scoring.gust_red_kmh');
    }

    /** Seuil rafale orange applicable à un set de conditions (override ou défaut global). */
    private function gustOrangeFor(FlyingConditions $conditions): float
    {
        return $conditions->getWindGustOrangeKmh() ?? $this->gustOrangeDefault;
    }

    /** Seuil rafale rouge applicable à un set de conditions (override ou défaut global). */
    private function gustRedFor(FlyingConditions $conditions): float
    {
        return $conditions->getWindGustRedKmh() ?? $this->gustRedDefault;
    }

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
     *
     * Priorité au consensus pré-calculé par le sidecar
     * (`qui_vole_consensus`) : s'il existe une prévision consensus fraîche
     * pour ce créneau, ses valeurs sont reprises telles quelles. Sinon, on
     * retombe sur le calcul de consensus interne (voting logic) à partir des
     * autres modèles — qui restent fetchés pour l'affichage.
     */
    private function computeScoreForSlot(
        Site $site,
        string $forecastAt,
        Collection $modelForecasts
    ): ?array {
        $consensus = $this->freshConsensusForecast($modelForecasts);
        if ($consensus !== null) {
            return $this->buildScoreFromConsensus($site, $forecastAt, $consensus);
        }

        // Fallback : voting logic interne sur les autres modèles (le modèle
        // consensus est exclu — il est absent/périmé dans cette branche).
        $others = $modelForecasts->reject(
            fn ($f) => $f->weatherModel?->code === ConsensusApi::MODEL_CODE
        );
        if ($others->isEmpty()) {
            return null;
        }

        return $this->computeScoreByVoting($site, $forecastAt, $others);
    }

    /**
     * Retourne la prévision `qui_vole_consensus` fraîche du créneau, ou null.
     */
    private function freshConsensusForecast(Collection $modelForecasts): ?Forecast
    {
        $cutoff = now()->subMinutes(self::CONSENSUS_MAX_AGE_MINUTES);

        return $modelForecasts->first(function ($f) use ($cutoff) {
            return $f->weatherModel?->code === ConsensusApi::MODEL_CODE
                && $f->wind_direction !== null
                && $f->wind_speed_avg !== null
                && $f->fetched_at !== null
                && $f->fetched_at->gte($cutoff);
        });
    }

    /**
     * Construit le score d'un créneau directement à partir du consensus
     * fourni par l'API sidecar. Les valeurs de consensus sont reprises
     * telles quelles ; seules les règles métier (éliminatoires, couleurs)
     * sont appliquées côté Laravel. La confiance dérive des compteurs
     * `models_count` / `models_converging` fournis par l'API.
     */
    private function buildScoreFromConsensus(
        Site $site,
        string $forecastAt,
        Forecast $c
    ): array {
        $conditions = $site->conditions;

        $windDirConsensus   = (float) $c->wind_direction;
        $windSpeedConsensus = (float) $c->wind_speed_avg;
        $windGustConsensus  = $c->wind_speed_max !== null ? (float) $c->wind_speed_max : $windSpeedConsensus;
        $precipConsensus    = $c->precipitation !== null ? (float) $c->precipitation : 0.0;
        $cloudBaseConsensus = $c->cloud_base_m !== null ? (float) $c->cloud_base_m : null;

        $status = $this->applyEliminatoryRules(
            $conditions,
            $windDirConsensus,
            $windSpeedConsensus,
            $windGustConsensus,
            $precipConsensus
        );

        $modelsCount      = (int) ($c->models_count ?? 0);
        $modelsConverging = (int) ($c->models_converging ?? 0);
        $convergence      = $modelsCount > 0 ? $modelsConverging / $modelsCount : 0.0;

        $confidencePct = (int) round($convergence * 100);
        if ($status === 'orange') {
            $confidencePct = min($confidencePct, 60);
        }

        $colors = $this->computeParamColors(
            $conditions,
            $windDirConsensus,
            $windSpeedConsensus,
            $windGustConsensus,
            $precipConsensus,
            $cloudBaseConsensus
        );

        // L'API ne fournit pas la convergence par paramètre (un seul couple
        // count/converging global). On reporte le ratio global sur chaque
        // paramètre — `values` ne contient que la valeur consensus (les
        // valeurs par modèle restent disponibles via /multimodel).
        $detail = [
            'wind_dir' => [
                'consensus'   => $windDirConsensus,
                'convergence' => round($convergence, 2),
                'values'      => [$windDirConsensus],
                'color'       => $colors['wind_dir'],
                'source'      => 'consensus_api',
            ],
            'wind_speed' => [
                'consensus'   => $windSpeedConsensus,
                'convergence' => round($convergence, 2),
                'values'      => [$windSpeedConsensus],
                'color'       => $colors['wind_speed'],
            ],
            'wind_gust' => [
                'consensus' => $windGustConsensus,
                'values'    => [$windGustConsensus],
                'color'     => $colors['wind_gust'],
            ],
            'precip' => [
                'consensus'   => $precipConsensus,
                'convergence' => round($convergence, 2),
                'values'      => [$precipConsensus],
                'color'       => $colors['precip'],
            ],
            'cloud_base' => [
                'consensus' => $cloudBaseConsensus !== null ? (int) round($cloudBaseConsensus) : null,
                'values'    => $cloudBaseConsensus !== null ? [(int) round($cloudBaseConsensus)] : [],
                'color'     => $colors['cloud_base'],
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

    /**
     * Calcule le score d'un créneau par voting logic interne (consensus
     * multi-modèles calculé en PHP). Fallback quand le consensus pré-calculé
     * du sidecar est indisponible.
     */
    private function computeScoreByVoting(
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
            $windGustConsensus,
            $precipConsensus
        );

        // ── 4. Confiance globale ─────────────────────────────────
        // Moyenne pondérée des convergences (les éliminatoires pèsent plus)
        $confidencePct = (int) round(
            ($windDirConvergence   * 0.35
           + $windSpeedConvergence * 0.35
           + $precipConvergence    * 0.30) * 100
        );

        // Si orange, on plafonne la confiance (la situation est limite)
        if ($status === 'orange') {
            $confidencePct = min($confidencePct, 60);
        }

        // ── 5. Modèles convergents ───────────────────────────────
        $modelsCount      = count($windDirs);
        $modelsConverging = (int) round($modelsCount * min($windDirConvergence, $windSpeedConvergence));

        // ── 6. Couleurs par paramètre (voting logic détaillée) ──
        $colors = $this->computeParamColors(
            $conditions,
            $windDirConsensus,
            $windSpeedConsensus,
            $windGustConsensus,
            $precipConsensus,
            $cloudBaseConsensus
        );

        // ── 7. Détail JSON ───────────────────────────────────────
        $detail = [
            'wind_dir' => [
                'consensus'   => $windDirConsensus,
                'convergence' => round($windDirConvergence, 2),
                'values'      => array_column($windDirs, 'value'),
                'color'       => $colors['wind_dir'],
            ],
            'wind_speed' => [
                'consensus'   => $windSpeedConsensus,
                'convergence' => round($windSpeedConvergence, 2),
                'values'      => array_column($windSpeeds, 'value'),
                'color'       => $colors['wind_speed'],
            ],
            'wind_gust' => [
                'consensus' => $windGustConsensus,
                'values'    => array_column($windGusts, 'value'),
                'color'     => $colors['wind_gust'],
            ],
            'precip' => [
                'consensus'   => $precipConsensus,
                'convergence' => round($precipConvergence, 2),
                'values'      => array_column($precips, 'value'),
                'color'       => $colors['precip'],
            ],
            'cloud_base' => [
                'consensus' => $cloudBaseConsensus !== null ? (int) round($cloudBaseConsensus) : null,
                'values'    => array_column($cloudBases, 'value'),
                'color'     => $colors['cloud_base'],
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
        FlyingConditions $conditions,
        float $windDir,
        float $windSpeed,
        float $windGust,
        float $precip
    ): string {
        $gustOrange = $this->gustOrangeFor($conditions);
        $gustRed    = $this->gustRedFor($conditions);

        // ── Rouges (éliminatoires) ──────────────────────────────
        if ($precip > $this->precipRedMmh) {
            return 'red';
        }
        if ($windGust > $gustRed) {
            return 'red';
        }
        if (! $conditions->isWindDirectionFavorable((int) $windDir)) {
            return 'red';
        }
        if (! $conditions->isWindSpeedFavorable($windSpeed)) {
            return 'red';
        }

        // ── Oranges (prudence) ──────────────────────────────────
        if ($precip > $this->precipOrangeMmh) {
            return 'orange';
        }
        if ($windGust > $gustOrange) {
            return 'orange';
        }

        // Tout est OK
        return 'green';
    }

    // ── Re-scoring à partir d'un SiteScore déjà calculé ─────────

    /**
     * Recalcule statut + couleurs d'un `SiteScore` existant en appliquant
     * un autre set de `FlyingConditions` (typiquement celles d'un user).
     *
     * Le consensus multi-modèles (déjà persisté en colonnes du
     * `site_scores`) est réutilisé tel quel — on ne refait pas la moyenne
     * pondérée, on ne touche pas aux `forecasts`. Seules les règles
     * dépendantes des conditions sont rejouées.
     *
     * Retourne : `['status' => string, 'colors' => array{wind_dir,wind_speed,wind_gust,precip,cloud_base}]`.
     * La confiance (`confidence_pct`) ne dépend pas des conditions, on la
     * laisse intacte au niveau appelant.
     */
    public function rescore(FlyingConditions $conditions, SiteScore $score): array
    {
        $windDir   = (float) $score->wind_dir_consensus;
        $windSpeed = (float) $score->wind_speed_consensus;
        $windGust  = (float) $score->wind_gust_consensus;
        $precip    = (float) $score->precip_consensus;
        $cloudBase = $score->cloud_base_consensus !== null
            ? (float) $score->cloud_base_consensus
            : null;

        return [
            'status' => $this->applyEliminatoryRules($conditions, $windDir, $windSpeed, $windGust, $precip),
            'colors' => $this->computeParamColors($conditions, $windDir, $windSpeed, $windGust, $precip, $cloudBase),
        ];
    }

    // ── Couleurs par paramètre (onglet « Détail du scoring ») ──

    /**
     * Calcule la couleur green|orange|red de chaque paramètre de la voting
     * logic, en isolant le critère (contrairement au statut global qui est
     * agrégé). Logique alignée sur applyEliminatoryRules().
     *
     * @return array{wind_dir:string,wind_speed:string,wind_gust:string,precip:string,cloud_base:string}
     */
    public function computeParamColors(
        FlyingConditions $conditions,
        float $windDir,
        float $windSpeed,
        float $windGust,
        float $precip,
        ?float $cloudBase
    ): array {
        // Direction : binaire (dans l'axe / hors axe)
        $dirColor = $conditions->isWindDirectionFavorable((int) $windDir) ? 'green' : 'red';

        // Vitesse moyenne : binaire (dans la plage / hors plage)
        $speedColor = $conditions->isWindSpeedFavorable($windSpeed) ? 'green' : 'red';

        // Rafales : seuils globaux (settings) ou surcharges du site
        $gustOrange = $this->gustOrangeFor($conditions);
        $gustRed    = $this->gustRedFor($conditions);
        $gustColor  = match (true) {
            $windGust > $gustRed    => 'red',
            $windGust > $gustOrange => 'orange',
            default                 => 'green',
        };

        // Précipitations : seuils globaux sur le consensus seulement
        $precipColor = match (true) {
            $precip > $this->precipRedMmh    => 'red',
            $precip > $this->precipOrangeMmh => 'orange',
            default                          => 'green',
        };

        // Plafond : informatif (pas éliminatoire). Vert si > cloud_base_min_m,
        // orange dans une marge de 100 m, rouge en-dessous. Unknown si pas
        // de seuil défini sur le site ou pas de consensus (ciel dégagé).
        $minCeil = $conditions->getCloudBaseMinM();
        if ($cloudBase === null || $minCeil === null) {
            $cloudColor = 'unknown';
        } elseif ($cloudBase < $minCeil) {
            $cloudColor = 'red';
        } elseif ($cloudBase < $minCeil + 100) {
            $cloudColor = 'orange';
        } else {
            $cloudColor = 'green';
        }

        return [
            'wind_dir'   => $dirColor,
            'wind_speed' => $speedColor,
            'wind_gust'  => $gustColor,
            'precip'     => $precipColor,
            'cloud_base' => $cloudColor,
        ];
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
