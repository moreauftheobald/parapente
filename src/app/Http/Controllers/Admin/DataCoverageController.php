<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DataCoverage;
use Illuminate\View\View;

/**
 * BackOffice — Couverture des données météo.
 *
 * Affiche 4 tableaux :
 *   1. Modèles météo (fraîcheur des fetches)
 *   2. Prévisions sites      (J → J+4)
 *   3. Prévisions balises    (J-7 → J+5)
 *   4. Relevés balises       (J-7 → J)
 *
 * Le calcul est porté par App\Services\DataCoverage (cache Redis 5 min).
 */
class DataCoverageController extends Controller
{
    public function index(DataCoverage $coverage): View
    {
        return view('admin.data-coverage.index', [
            'modelFreshness'   => $coverage->modelFreshness(),
            'siteForecasts'    => $coverage->siteForecastCoverage(),
            'baliseForecasts'  => $coverage->baliseForecastCoverage(),
            'baliseReadings'   => $coverage->baliseReadingsCoverage(),
            'today'            => \Carbon\CarbonImmutable::now()->startOfDay(),
        ]);
    }
}
