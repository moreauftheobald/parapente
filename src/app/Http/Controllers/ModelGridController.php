<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WeatherModel;
use App\Services\Map\ModelGridBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Module « Carte des modèles » — visualisation didactique de la grille
 * d'un modèle météo NWP avec coloration par fiabilité agrégée des
 * balises tombant dans chaque cellule.
 *
 * Module à accès admin uniquement (cf. table `modules`,
 * `access_level = 'admin'`), le temps que la couverture du panel de
 * balises soit suffisante. Le contrôleur reste **non-admin** (pas dans
 * `Http\Controllers\Admin\*`) car c'est conceptuellement une page front,
 * juste filtrée par module visibility + middleware d'autorisation.
 */
class ModelGridController extends Controller
{
    public function __construct(private ModelGridBuilder $gridBuilder)
    {
    }

    public function index(): View
    {
        // Le modèle consensus n'a pas de grille NWP propre ni de fiabilité
        // par balise — on l'exclut du sélecteur de la carte des modèles.
        $models = WeatherModel::query()
            ->where('active', true)
            ->where('code', '!=', \App\Services\Weather\Apis\ConsensusApi::MODEL_CODE)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'resolution_km']);

        return view('model-grid.index', [
            'models'     => $models,
            'variables'  => [
                'wind_speed_avg' => 'Vent moyen (km/h)',
                'wind_speed_max' => 'Rafales (km/h)',
                'wind_direction' => 'Direction (°)',
            ],
            'buckets'    => [
                'nowcast'  => 'nowcast (0-6h)',
                'same_day' => 'same-day (6-24h)',
                'j_plus_1' => 'J+1 (24-48h)',
                'j_plus_2' => 'J+2 (48-72h)',
            ],
            'metrics'    => [
                'weight_factor' => 'Fiabilité (weight_factor)',
                'mae'           => 'Erreur absolue (MAE)',
            ],
        ]);
    }

    /**
     * Endpoint GeoJSON consommé par la carte Leaflet en AJAX.
     *
     * Paramètres GET :
     *  - model_id  (int, requis)
     *  - variable  (enum, défaut wind_speed_avg)
     *  - bucket    (enum, défaut nowcast)
     *  - south,west,north,east  (float, bbox, requis)
     *  - show_empty (0|1, défaut 1 : on affiche aussi les cellules vides)
     */
    public function data(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_id'   => ['required', 'integer', 'exists:weather_models,id'],
            'variable'   => ['nullable', 'in:wind_speed_avg,wind_speed_max,wind_direction'],
            'bucket'     => ['nullable', 'in:nowcast,same_day,j_plus_1,j_plus_2'],
            'south'      => ['required', 'numeric', 'between:-90,90'],
            'west'       => ['required', 'numeric', 'between:-180,180'],
            'north'      => ['required', 'numeric', 'between:-90,90'],
            'east'       => ['required', 'numeric', 'between:-180,180'],
            'show_empty' => ['nullable', 'boolean'],
        ]);

        $geoJson = $this->gridBuilder->build(
            modelId:   (int) $validated['model_id'],
            variable:  $validated['variable'] ?? 'wind_speed_avg',
            bucket:    $validated['bucket']   ?? 'nowcast',
            bbox:      [
                'south' => (float) $validated['south'],
                'west'  => (float) $validated['west'],
                'north' => (float) $validated['north'],
                'east'  => (float) $validated['east'],
            ],
            showEmpty: (bool) ($validated['show_empty'] ?? true),
        );

        return response()->json($geoJson);
    }
}
