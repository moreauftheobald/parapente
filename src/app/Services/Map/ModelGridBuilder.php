<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\Balise;
use App\Models\ModelReliability;
use App\Models\WeatherModel;
use Illuminate\Support\Collection;

/**
 * Construit la grille d'un modèle météo en GeoJSON, colorée par la
 * fiabilité agrégée des balises tombant dans chaque cellule.
 *
 * Approche :
 *  1. Discrétise la viewport (bbox) en cellules alignées sur la grille
 *     0°/0° du modèle, à pas constant = résolution déclarée du modèle.
 *  2. Pour chaque cellule, identifie les balises du panel
 *     `in_consensus_compare_panel` dont les coordonnées tombent dedans.
 *  3. Si ≥ 1 balise : agrège `model_reliability` (moyennes pondérées
 *     par `samples_n` pour `mae`/`weight_factor`/`bias_signed`, somme
 *     pour `samples_n`).
 *  4. Si 0 balise : feature `is_occupied = false` rendue en grille
 *     vide (contour léger) côté frontend, **uniquement** si le mode
 *     « grille complète » est demandé (cf. paramètre `show_empty`).
 *
 * Hypothèse : alignement standard de la grille à l'origine 0° lat / 0°
 * lng, pas constant en degrés. Approximation honnête pour la France
 * métropolitaine (lat 42-51°). Pour des projections Lambert (AROME),
 * Open-Meteo ré-échantillonne déjà en lat/lon, donc la grille apparente
 * vue par l'API est régulière.
 *
 * Cf. FF_model_reliability.md et CLAUDE.md (module « Carte des modèles »).
 */
class ModelGridBuilder
{
    /**
     * Nombre maximal de cellules sur l'axe le plus large de la viewport
     * pour calculer le zoom minimum recommandé d'un modèle. Un peu
     * confortable : 64 cellules par dimension = ~4 000 cellules visibles
     * maximum (très tractable côté Leaflet).
     */
    private const TARGET_CELLS_PER_DIM = 64;

    /**
     * Construit la grille pour un modèle, une variable, un bucket et une
     * bbox. Retourne un FeatureCollection GeoJSON-ready (tableau PHP).
     *
     * @param array{south:float,west:float,north:float,east:float} $bbox
     *
     * @return array{
     *   type: 'FeatureCollection',
     *   metadata: array<string, mixed>,
     *   features: array<int, array<string, mixed>>
     * }
     */
    public function build(
        int $modelId,
        string $variable,
        string $bucket,
        array $bbox,
        bool $showEmpty = true
    ): array {
        $model = WeatherModel::query()->findOrFail($modelId);
        $resolutionDeg = $this->resolutionDegrees($model);

        // Discrétisation des bornes sur la grille alignée 0°/0°
        $latMin = $this->floorToGrid($bbox['south'], $resolutionDeg);
        $latMax = $this->ceilToGrid($bbox['north'], $resolutionDeg);
        $lngMin = $this->floorToGrid($bbox['west'],  $resolutionDeg);
        $lngMax = $this->ceilToGrid($bbox['east'],  $resolutionDeg);

        // Garde-fou : si la viewport est gigantesque pour la résolution du
        // modèle, on retourne une grille vide avec un flag — le frontend
        // affichera un message "zoom davantage".
        $latCells = (int) round(($latMax - $latMin) / $resolutionDeg);
        $lngCells = (int) round(($lngMax - $lngMin) / $resolutionDeg);
        $totalCells = $latCells * $lngCells;
        $maxCells = self::TARGET_CELLS_PER_DIM * self::TARGET_CELLS_PER_DIM * 4; // ~16 000
        if ($totalCells > $maxCells) {
            return [
                'type'     => 'FeatureCollection',
                'metadata' => [
                    'model'           => $this->modelMetadata($model, $resolutionDeg),
                    'too_large'       => true,
                    'cells_requested' => $totalCells,
                    'cells_max'       => $maxCells,
                    'recommended_min_zoom' => $this->recommendedMinZoom($resolutionDeg),
                ],
                'features' => [],
            ];
        }

        // Balises du panel, filtrées sur la bbox étendue à la grille
        $balises = Balise::query()
            ->where('active', true)
            ->where('in_consensus_compare_panel', true)
            ->whereBetween('latitude',  [$latMin, $latMax])
            ->whereBetween('longitude', [$lngMin, $lngMax])
            ->get(['id', 'name', 'latitude', 'longitude']);

        // Fiabilité du modèle pour ce (variable, bucket) sur les balises retenues
        $reliabilities = ModelReliability::query()
            ->where('weather_model_id', $modelId)
            ->where('variable', $variable)
            ->where('horizon_bucket', $bucket)
            ->whereIn('balise_id', $balises->pluck('id'))
            ->get()
            ->keyBy('balise_id');

        // Map balise → cellule (clé composite "ilat|ilng")
        $byCell = $this->groupBalisesByCell($balises, $resolutionDeg, $latMin, $lngMin);

        // Construction des features
        $features = [];
        $occupiedCount = 0;
        for ($i = 0; $i < $latCells; $i++) {
            for ($j = 0; $j < $lngCells; $j++) {
                $cellLatMin = $latMin + $i * $resolutionDeg;
                $cellLngMin = $lngMin + $j * $resolutionDeg;
                $cellKey    = $i . '|' . $j;
                $cellBalises = $byCell[$cellKey] ?? collect();

                $isOccupied = $cellBalises->isNotEmpty();

                if (! $isOccupied && ! $showEmpty) {
                    continue;
                }

                $props = [
                    'is_occupied' => $isOccupied,
                    'balise_count' => $cellBalises->count(),
                ];

                if ($isOccupied) {
                    $agg = $this->aggregateCell($cellBalises, $reliabilities);
                    $props = array_merge($props, $agg);
                    if ($agg['samples_n'] > 0) {
                        $occupiedCount++;
                    }
                }

                $features[] = [
                    'type'       => 'Feature',
                    'properties' => $props,
                    'geometry'   => [
                        'type'        => 'Polygon',
                        'coordinates' => [[
                            [$cellLngMin,                  $cellLatMin],
                            [$cellLngMin + $resolutionDeg, $cellLatMin],
                            [$cellLngMin + $resolutionDeg, $cellLatMin + $resolutionDeg],
                            [$cellLngMin,                  $cellLatMin + $resolutionDeg],
                            [$cellLngMin,                  $cellLatMin],
                        ]],
                    ],
                ];
            }
        }

        return [
            'type'     => 'FeatureCollection',
            'metadata' => [
                'model'                => $this->modelMetadata($model, $resolutionDeg),
                'variable'             => $variable,
                'horizon_bucket'       => $bucket,
                'cells_total'          => $totalCells,
                'cells_occupied'       => $occupiedCount,
                'balise_count_in_bbox' => $balises->count(),
                'recommended_min_zoom' => $this->recommendedMinZoom($resolutionDeg),
                'too_large'            => false,
            ],
            'features' => $features,
        ];
    }

    /**
     * Calcule le zoom Leaflet minimum recommandé pour qu'un modèle de
     * résolution donnée n'affiche pas trop de cellules. Formule :
     *   min_zoom = ceil(log2(360 / (resolution_deg × TARGET_CELLS_PER_DIM))) + 1
     *
     * Le +1 final compense le ratio largeur/hauteur typique d'une
     * viewport (~16:9 sur desktop), que la formule théorique ignore.
     * Sans cette marge, à zoom recommandé l'affichage déclenche en
     * pratique le seuil too_large car la bbox réelle est plus large
     * que haute.
     */
    public function recommendedMinZoom(float $resolutionDeg): int
    {
        $ratio = 360.0 / ($resolutionDeg * self::TARGET_CELLS_PER_DIM);
        return max(1, (int) ceil(log($ratio, 2)) + 1);
    }

    /**
     * Résolution équivalente en degrés. On part de `resolution_km` et on
     * approxime 1° lat ≈ 111 km. C'est une approximation honnête côté
     * latitude ; côté longitude, on garde la même valeur (la grille
     * Open-Meteo est rectangulaire en degrés, pas en km).
     */
    private function resolutionDegrees(WeatherModel $model): float
    {
        $resolutionKm = (float) ($model->resolution_km ?? 10.0);
        if ($resolutionKm <= 0) {
            $resolutionKm = 10.0;
        }
        return $resolutionKm / 111.0;
    }

    private function floorToGrid(float $value, float $step): float
    {
        return floor($value / $step) * $step;
    }

    private function ceilToGrid(float $value, float $step): float
    {
        return ceil($value / $step) * $step;
    }

    /**
     * @return Collection<string, Collection<int, Balise>>
     */
    private function groupBalisesByCell(
        Collection $balises,
        float $resolutionDeg,
        float $latMin,
        float $lngMin
    ): Collection {
        return $balises
            ->map(function (Balise $b) use ($resolutionDeg, $latMin, $lngMin) {
                $i = (int) floor(((float) $b->latitude  - $latMin) / $resolutionDeg);
                $j = (int) floor(((float) $b->longitude - $lngMin) / $resolutionDeg);
                $b->setAttribute('_cell_key', $i . '|' . $j);
                return $b;
            })
            ->groupBy('_cell_key');
    }

    /**
     * Agrège les métriques de fiabilité sur les balises d'une cellule.
     * Moyenne pondérée par `samples_n` pour `mae`, `weight_factor`,
     * `bias_signed`. Somme pour `samples_n`. Liste des `balise_id`
     * incluse pour affichage popup.
     *
     * @param Collection<int, Balise> $cellBalises
     * @param Collection<int, ModelReliability> $reliabilities  (keyBy balise_id)
     *
     * @return array<string, mixed>
     */
    private function aggregateCell(Collection $cellBalises, Collection $reliabilities): array
    {
        $sumWeight    = 0.0;
        $sumMae       = 0.0;
        $sumWFactor   = 0.0;
        $sumBias      = 0.0;
        $samplesTotal = 0;
        $baliseIds    = [];
        $baliseNames  = [];

        foreach ($cellBalises as $b) {
            $r = $reliabilities->get($b->id);
            if ($r === null || $r->samples_n <= 0) {
                continue;
            }
            $w = (float) $r->samples_n;
            $sumWeight   += $w;
            $sumMae      += (float) $r->mae * $w;
            $sumWFactor  += (float) $r->weight_factor * $w;
            $sumBias     += (float) $r->bias_signed * $w;
            $samplesTotal += (int) $r->samples_n;
            $baliseIds[]  = (int) $b->id;
            $baliseNames[] = $b->name;
        }

        if ($sumWeight <= 0) {
            return [
                'mae'           => null,
                'weight_factor' => null,
                'bias_signed'   => null,
                'samples_n'     => 0,
                'balise_ids'    => $cellBalises->pluck('id')->all(),
                'balise_names'  => $cellBalises->pluck('name')->all(),
            ];
        }

        return [
            'mae'           => round($sumMae / $sumWeight, 2),
            'weight_factor' => round($sumWFactor / $sumWeight, 2),
            'bias_signed'   => round($sumBias / $sumWeight, 2),
            'samples_n'     => $samplesTotal,
            'balise_ids'    => $baliseIds,
            'balise_names'  => $baliseNames,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modelMetadata(WeatherModel $model, float $resolutionDeg): array
    {
        return [
            'id'             => $model->id,
            'name'           => $model->name,
            'code'           => $model->code,
            'resolution_km'  => $model->resolution_km !== null ? (float) $model->resolution_km : null,
            'resolution_deg' => round($resolutionDeg, 4),
        ];
    }
}
