<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\Site;
use App\Models\StationApi;
use App\Models\WeatherStation;
use App\Services\DataCoverage;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — écran « Data / couverture » : regroupe les anciens onglets
 * « data » des pages de paramètres par section en un seul écran à 4
 * onglets (modèles / sites / balises / stations) : fraîcheur des fetches,
 * couverture des prévisions et observations, imports et découverte.
 *
 * Cf. FF_admin_redesign.md (étape 4 — consolidation).
 */
class DataController extends Controller
{
    public function index(Request $request, Settings $settings, DataCoverage $coverage): View
    {
        $tab = in_array($request->query('tab'), ['models', 'sites', 'balises', 'stations'], true)
            ? $request->query('tab')
            : 'models';

        $data = [
            'tab'   => $tab,
            'today' => CarbonImmutable::now()->startOfDay(),
        ];

        if ($tab === 'models') {
            $data['modelFreshness'] = $coverage->modelFreshness();
        }

        if ($tab === 'sites') {
            $data['countries']     = DataSyncController::ISO_COUNTRIES;
            $data['sitesTotal']    = Site::count();
            $data['sitesPge']      = Site::where('source', 'paraglidingearth')->count();
            $data['siteForecasts'] = $coverage->siteForecastCoverage();
        }

        if ($tab === 'balises') {
            $data['bbox']               = DataSyncController::DEFAULT_BBOX;
            $data['balisesPiou']        = Balise::where('source', 'pioupiou')->count();
            $data['balisesWindy']       = Balise::where('source', 'windy')->count();
            $data['windyKeyConfigured'] = trim((string) $settings->get('windy.api_key', '')) !== '';
            $data['baliseForecasts']    = $coverage->baliseForecastCoverage();
            $data['baliseReadings']     = $coverage->baliseReadingsCoverage();
        }

        if ($tab === 'stations') {
            $stationApis = StationApi::all()->keyBy('code');
            $data['bbox']            = DataSyncController::DEFAULT_BBOX;
            $data['stationApis']     = $stationApis;
            $data['mfKeyConfigured'] = $stationApis->get('mf')?->hasOAuth2Credentials() ?? false;
            $data['icKeyConfigured'] = ! empty($stationApis->get('infoclimat')?->api_key);
            $data['stationCounts']   = [
                'mf'         => WeatherStation::where('network', 'mf')->count(),
                'metar'      => WeatherStation::where('network', 'metar')->count(),
                'infoclimat' => WeatherStation::where('network', 'infoclimat')->count(),
            ];
            $data['stationForecasts'] = $coverage->stationForecastCoverage();
            $data['stationReadings']  = $coverage->stationReadingsCoverage();
        }

        return view('admin.data.index', $data);
    }
}
