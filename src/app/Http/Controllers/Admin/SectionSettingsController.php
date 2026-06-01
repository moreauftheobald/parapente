<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\Site;
use App\Services\DataCoverage;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SectionSettingsController extends Controller
{
    public function contenu(Request $request): View
    {
        return view('admin.contenu.settings', [
            'tab' => $this->resolveTab($request),
        ]);
    }

    public function meteo(Request $request, DataCoverage $coverage): View
    {
        $tab  = $this->resolveTab($request);
        $data = ['tab' => $tab];

        if ($tab === 'data') {
            $data['modelFreshness'] = $coverage->modelFreshness();
            $data['today']          = CarbonImmutable::now()->startOfDay();
        }

        return view('admin.meteo.settings', $data);
    }

    public function sites(Request $request, DataCoverage $coverage): View
    {
        $tab  = $this->resolveTab($request);
        $data = [
            'tab'        => $tab,
            'countries'  => DataSyncController::ISO_COUNTRIES,
            'sitesTotal' => Site::count(),
            'sitesPge'   => Site::where('source', 'paraglidingearth')->count(),
        ];

        if ($tab === 'data') {
            $data['siteForecasts'] = $coverage->siteForecastCoverage();
            $data['today']         = CarbonImmutable::now()->startOfDay();
        }

        return view('admin.sites.settings', $data);
    }

    public function balises(Request $request, Settings $settings, DataCoverage $coverage): View
    {
        $tab  = $this->resolveTab($request);
        $data = [
            'tab'                => $tab,
            'bbox'               => DataSyncController::DEFAULT_BBOX,
            'balisesPiou'        => Balise::where('source', 'pioupiou')->count(),
            'balisesMetar'       => Balise::where('source', 'metar')->count(),
            'balisesWindy'       => Balise::where('source', 'windy')->count(),
            'windyKeyConfigured' => trim((string) $settings->get('windy.api_key', '')) !== '',
        ];

        if ($tab === 'data') {
            $data['baliseForecasts'] = $coverage->baliseForecastCoverage();
            $data['baliseReadings']  = $coverage->baliseReadingsCoverage();
            $data['today']           = CarbonImmutable::now()->startOfDay();
        }

        return view('admin.balises.settings', $data);
    }

    private function resolveTab(Request $request): string
    {
        $allowed = ['general', 'data', 'logs'];

        return in_array($request->query('tab'), $allowed, true)
            ? $request->query('tab')
            : 'general';
    }
}
