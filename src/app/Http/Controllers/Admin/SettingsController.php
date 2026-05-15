<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — paramètres globaux de l'application.
 *
 * Tous les seuils de scoring (précipitations, rafales, viabilité du jour)
 * sont stockés dans la table `settings` et accessibles via le service
 * `App\Services\Settings`. Cette page permet de les éditer en bloc.
 *
 * Les modifications prennent effet immédiatement (cache Redis invalidé
 * à l'enregistrement) pour les nouveaux scores. Les scores déjà en base
 * sont mis à jour au prochain fetch horaire — ou immédiatement via
 * `php artisan scores:recompute-detail-colors` pour les couleurs des
 * paramètres (le statut global est recalculé au prochain fetch).
 */
class SettingsController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function index(): View
    {
        $values = $this->settings->all();

        // Regroupement par section (cf. catalogue Settings::DEFAULTS) :
        $groups = [
            'precip'    => ['title' => 'Précipitations',           'icon' => 'fa-cloud-rain',     'keys' => []],
            'gust'      => ['title' => 'Rafales',                  'icon' => 'fa-tornado',        'keys' => []],
            'viability' => ['title' => "Viabilité d'une journée",  'icon' => 'fa-chart-line',     'keys' => []],
            'quality'   => ['title' => 'Qualité des données',      'icon' => 'fa-clipboard-check','keys' => []],
        ];
        foreach (Settings::DEFAULTS as $key => $meta) {
            $g = $meta['group'] ?? 'misc';
            if (! isset($groups[$g])) {
                $groups[$g] = ['title' => ucfirst($g), 'icon' => 'fa-gear', 'keys' => []];
            }
            $groups[$g]['keys'][$key] = $meta + [
                'value'   => $values[$key] ?? $meta['default'],
                'default' => $meta['default'],
            ];
        }

        return view('admin.settings.edit', compact('groups'));
    }

    public function update(Request $request): RedirectResponse
    {
        // Construction dynamique des règles de validation à partir du
        // catalogue (type int vs float).
        $rules = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            $name = $this->keyToField($key);
            $rules[$name] = match ($meta['type'] ?? 'float') {
                'int'   => ['required', 'integer', 'min:0'],
                default => ['required', 'numeric', 'min:0'],
            };
        }
        $data = $request->validate($rules);

        $values = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            $name = $this->keyToField($key);
            $raw  = $data[$name];
            $values[$key] = ($meta['type'] ?? 'float') === 'int'
                ? (int) $raw
                : (float) $raw;
        }

        $this->settings->setMany($values);

        return redirect()
            ->route('admin.settings.index')
            ->with('status', 'Paramètres généraux enregistrés.');
    }

    /**
     * Les noms de champs HTML utilisent `_` à la place du `.` (qui n'est
     * pas un caractère valide en clé de formulaire validé par Laravel).
     */
    private function keyToField(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
