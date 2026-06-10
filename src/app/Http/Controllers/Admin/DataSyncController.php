<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\Site;
use App\Models\WeatherStation;
use App\Services\GeoDeploymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * BackOffice — synchronisation des données de référence.
 *
 * Déclenche depuis l'interface les imports/découvertes habituellement
 * lancés en CLI :
 *   - sites:import          → sites de vol depuis ParaglidingEarth
 *   - balises:discover      → balises PiouPiou / Windy dans une bbox
 *
 * Les opérations sont exécutées de façon synchrone (un appel HTTP vers
 * l'API externe + upserts). La sortie de la commande Artisan est
 * renvoyée à la vue pour affichage. Ces commandes sont idempotentes :
 * relancer met à jour l'existant sans rien désactiver.
 */
class DataSyncController extends Controller
{
    /** Codes ISO pays proposés pour l'import de sites */
    public const ISO_COUNTRIES = [
        'fr' => 'France',
        'ch' => 'Suisse',
        'be' => 'Belgique',
        'de' => 'Allemagne',
        'lu' => 'Luxembourg',
        'it' => 'Italie',
        'es' => 'Espagne',
    ];

    /** Bounding box par défaut : large couverture (France + zones frontalières) */
    public const DEFAULT_BBOX = [
        'lat_min' => 47.0,
        'lat_max' => 50.5,
        'lng_min' => 3.5,
        'lng_max' => 8.5,
    ];

    /**
     * Importe les sites de vol d'un pays depuis ParaglidingEarth.
     */
    public function importSites(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'iso'   => ['required', 'string', 'in:' . implode(',', array_keys(self::ISO_COUNTRIES))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20000'],
        ]);

        @set_time_limit(300);

        $params = ['--iso' => $data['iso']];
        if (! empty($data['limit'])) {
            $params['--limit'] = (int) $data['limit'];
        }

        Artisan::call('sites:import', $params);

        return redirect()
            ->route('admin.data.index', ['tab' => 'sites'])
            ->with('status', 'Import des sites (' . self::ISO_COUNTRIES[$data['iso']] . ') terminé.')
            ->with('sync_output', trim(Artisan::output()));
    }

    /**
     * Découvre / met à jour les balises d'un fournisseur dans une bbox.
     */
    public function discoverBalises(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source'  => ['required', 'in:pioupiou,windy'],
            'lat_min' => ['required', 'numeric', 'between:-90,90'],
            'lat_max' => ['required', 'numeric', 'between:-90,90', 'gt:lat_min'],
            'lng_min' => ['required', 'numeric', 'between:-180,180'],
            'lng_max' => ['required', 'numeric', 'between:-180,180', 'gt:lng_min'],
        ]);

        @set_time_limit(300);

        Artisan::call('balises:discover', [
            '--source'  => $data['source'],
            '--lat-min' => $data['lat_min'],
            '--lat-max' => $data['lat_max'],
            '--lng-min' => $data['lng_min'],
            '--lng-max' => $data['lng_max'],
        ]);

        $label = match ($data['source']) {
            'pioupiou' => 'PiouPiou',
            'windy'    => 'Windy',
            default    => $data['source'],
        };

        return redirect()
            ->route('admin.data.index', ['tab' => 'balises'])
            ->with('status', 'Découverte des balises ' . $label . ' terminée.')
            ->with('sync_output', trim(Artisan::output()));
    }

    /**
     * Découvre / met à jour les stations météo d'un réseau dans une bbox.
     */
    public function discoverWeatherStations(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'network' => ['required', 'in:mf,metar,infoclimat'],
            'lat_min' => ['required', 'numeric', 'between:-90,90'],
            'lat_max' => ['required', 'numeric', 'between:-90,90', 'gt:lat_min'],
            'lng_min' => ['required', 'numeric', 'between:-180,180'],
            'lng_max' => ['required', 'numeric', 'between:-180,180', 'gt:lng_min'],
        ]);

        @set_time_limit(300);

        Artisan::call('weather-stations:discover', [
            '--network' => $data['network'],
            '--lat-min' => $data['lat_min'],
            '--lat-max' => $data['lat_max'],
            '--lng-min' => $data['lng_min'],
            '--lng-max' => $data['lng_max'],
        ]);

        $label = match ($data['network']) {
            'mf'         => 'Météo-France',
            'metar'      => 'METAR',
            'infoclimat' => 'Infoclimat',
            default      => $data['network'],
        };

        return redirect()
            ->route('admin.data.index', ['tab' => 'stations'])
            ->with('status', 'Découverte des stations ' . $label . ' terminée.')
            ->with('sync_output', trim(Artisan::output()));
    }

    /**
     * Déploiement géographique : géocode une ville, calcule un rayon et
     * active sites + balises (pioupiou, windy) dans la zone.
     */
    public function deploy(Request $request, GeoDeploymentService $service): RedirectResponse
    {
        $data = $request->validate([
            'city'      => ['required', 'string', 'max:120'],
            'radius_km' => ['required', 'numeric', 'min:1', 'max:500'],
        ]);

        @set_time_limit(300);

        try {
            $report = $service->deploy(trim($data['city']), (float) $data['radius_km']);
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['city' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('status', 'Déploiement géographique terminé.')
            ->with('deploy_report', $report);
    }
}
