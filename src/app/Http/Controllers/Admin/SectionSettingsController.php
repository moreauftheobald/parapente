<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\Site;
use App\Services\Settings;
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

    public function meteo(Request $request): View
    {
        return view('admin.meteo.settings', [
            'tab' => $this->resolveTab($request),
        ]);
    }

    public function sites(Request $request): View
    {
        return view('admin.sites.settings', [
            'tab'        => $this->resolveTab($request),
            'countries'  => DataSyncController::ISO_COUNTRIES,
            'sitesTotal' => Site::count(),
            'sitesPge'   => Site::where('source', 'paraglidingearth')->count(),
        ]);
    }

    public function balises(Request $request, Settings $settings): View
    {
        return view('admin.balises.settings', [
            'tab'                => $this->resolveTab($request),
            'bbox'               => DataSyncController::DEFAULT_BBOX,
            'balisesPiou'        => Balise::where('source', 'pioupiou')->count(),
            'balisesMetar'       => Balise::where('source', 'metar')->count(),
            'balisesWindy'       => Balise::where('source', 'windy')->count(),
            'windyKeyConfigured' => trim((string) $settings->get('windy.api_key', '')) !== '',
        ]);
    }

    private function resolveTab(Request $request): string
    {
        $allowed = ['general', 'data', 'logs'];

        return in_array($request->query('tab'), $allowed, true)
            ? $request->query('tab')
            : 'general';
    }
}
