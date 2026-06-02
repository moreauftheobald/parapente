<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — paramètres analytics / trafic.
 *
 * Les autres groupes de paramètres (scoring, balises, fiabilité) ont été
 * migrés dans leurs sections dédiées :
 *   - precip / gust / viability / quality → admin.sites.settings (onglet général)
 *   - balises                             → admin.balises.settings (onglet général)
 *   - reliability                         → admin.meteo.settings (onglet général)
 *   - consensus_global / consensus_scheduler → admin.meteo.settings (onglets consensus / orchestration)
 */
class SettingsController extends Controller
{
    /** Groupes gérés par ce contrôleur (les autres sont dans SectionSettingsController). */
    private const MANAGED_GROUPS = ['analytics'];

    public function __construct(private Settings $settings)
    {
    }

    public function index(): View
    {
        $values = $this->settings->all();

        $groups = [
            'analytics' => ['title' => 'Trafic / analytics', 'icon' => 'fa-chart-line', 'keys' => []],
        ];

        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            $g = $meta['group'] ?? 'misc';
            if (! isset($groups[$g])) {
                continue;
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
        // Seuls les settings du groupe analytics sont traités ici.
        $rules = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            if (! in_array($meta['group'] ?? '', self::MANAGED_GROUPS, true)) {
                continue;
            }
            $name          = $this->keyToField($key);
            $rules[$name]  = match ($meta['type'] ?? 'float') {
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
            if (! in_array($meta['group'] ?? '', self::MANAGED_GROUPS, true)) {
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
            ->with('status', 'Paramètres analytics enregistrés.');
    }

    /**
     * Les noms de champs HTML utilisent `__` à la place du `.` (qui n'est
     * pas un caractère valide en clé de formulaire validé par Laravel).
     */
    private function keyToField(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
