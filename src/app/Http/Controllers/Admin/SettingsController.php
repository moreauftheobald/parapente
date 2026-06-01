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
            'precip'               => ['title' => 'Précipitations',                'icon' => 'fa-cloud-rain',      'keys' => []],
            'gust'                 => ['title' => 'Rafales',                       'icon' => 'fa-tornado',         'keys' => []],
            'viability'            => ['title' => "Viabilité d'une journée",       'icon' => 'fa-chart-line',      'keys' => []],
            'quality'              => ['title' => 'Qualité des données',           'icon' => 'fa-clipboard-check', 'keys' => []],
            'balises'              => ['title' => 'Sources balises',               'icon' => 'fa-tower-broadcast', 'keys' => []],
            'analytics'            => ['title' => 'Trafic / analytics',            'icon' => 'fa-chart-line',      'keys' => []],
            'reliability'          => ['title' => 'Fiabilité des modèles',         'icon' => 'fa-flask-vial',      'keys' => []],
            'consensus_global'     => ['title' => 'Consensus sidecar — globaux',   'icon' => 'fa-sliders',         'keys' => []],
            'consensus_scheduler'  => ['title' => 'Orchestration sidecar',         'icon' => 'fa-clock',           'keys' => []],
        ];
        // JSON-typed settings (consensus per-variable config) are managed
        // on their own dedicated page — exclude them from the generic form.
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
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
        // catalogue (int / float / bool / secret-string).
        // JSON-typed settings are excluded (managed on their own page).
        $rules = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            $name = $this->keyToField($key);
            $rules[$name] = match ($meta['type'] ?? 'float') {
                'int'              => ['required', 'integer', 'min:0'],
                'bool'             => ['required', 'boolean'],
                'string', 'secret' => ['nullable', 'string', 'max:500'],
                default            => ['required', 'numeric', 'min:0'],
            };
        }
        $data = $request->validate($rules);

        $values = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            $name = $this->keyToField($key);
            $raw  = $data[$name] ?? null;
            $type = $meta['type'] ?? 'float';

            $values[$key] = match ($type) {
                'int'              => (int) $raw,
                'bool'             => (bool) filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'string', 'secret' => trim((string) ($raw ?? '')),
                default            => (float) $raw,
            };
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
