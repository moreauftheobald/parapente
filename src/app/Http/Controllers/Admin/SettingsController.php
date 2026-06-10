<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BackOffice — hub des paramètres, classés par catégories (onglets).
 *
 * Toutes les clés simples de `Settings::DEFAULTS` (hors type `json` —
 * la config consensus par variable garde son éditeur dédié dans
 * /admin/meteo/settings) sont éditables ici, avec leur description en
 * bulle d'aide. Chaque onglet est sauvegardé indépendamment ; l'audit
 * (`settings_audit`) est géré par le service Settings.
 *
 * NB : certaines pages de section (sites/balises/stations/météo) éditent
 * encore les mêmes groupes via leur onglet « général » — consolidation
 * prévue à l'étape 4 du FF_admin_redesign.md.
 */
class SettingsController extends Controller
{
    /**
     * Onglets du hub : clé → label, icône, groupes de settings couverts,
     * note optionnelle affichée en tête d'onglet.
     */
    public const CATEGORIES = [
        'scoring' => [
            'label'  => 'Scoring & viabilité',
            'icon'   => 'fa-gauge-high',
            'groups' => ['precip', 'gust', 'scoring', 'viability'],
        ],
        'balises' => [
            'label'  => 'Balises',
            'icon'   => 'fa-tower-broadcast',
            'groups' => ['balises'],
        ],
        'reliability' => [
            'label'  => 'Fiabilité',
            'icon'   => 'fa-scale-balanced',
            'groups' => ['reliability'],
        ],
        'quality' => [
            'label'  => 'Qualité données',
            'icon'   => 'fa-clone',
            'groups' => ['quality'],
        ],
        'analytics' => [
            'label'  => 'Trafic',
            'icon'   => 'fa-chart-line',
            'groups' => ['analytics'],
        ],
    ];

    /** Titres + icônes des sous-sections (groupes de Settings::DEFAULTS). */
    private const GROUP_META = [
        'precip'              => ['title' => 'Précipitations (seuils)',        'icon' => 'fa-cloud-showers-heavy'],
        'gust'                => ['title' => 'Rafales (seuils)',               'icon' => 'fa-wind'],
        'scoring'             => ['title' => 'Scoring global',                 'icon' => 'fa-gauge-high'],
        'viability'           => ['title' => 'Qualité de journée (viabilité)', 'icon' => 'fa-sun'],
        'quality'             => ['title' => 'Détection de doublons',          'icon' => 'fa-clone'],
        'analytics'           => ['title' => 'Trafic / analytics',             'icon' => 'fa-chart-line'],
        'balises'             => ['title' => 'Balises',                        'icon' => 'fa-tower-broadcast'],
        'reliability'         => ['title' => 'Fiabilité des modèles',          'icon' => 'fa-scale-balanced'],
    ];

    public function __construct(private Settings $settings)
    {
    }

    public function index(Request $request): View
    {
        $tab = (string) $request->query('tab', 'scoring');
        if (! isset(self::CATEGORIES[$tab])) {
            $tab = 'scoring';
        }

        return view('admin.settings.edit', [
            'tab'        => $tab,
            'categories' => self::CATEGORIES,
            'groups'     => $this->groupsForTab($tab),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tab = (string) $request->query('tab', 'scoring');
        if (! isset(self::CATEGORIES[$tab])) {
            $tab = 'scoring';
        }

        $rules = [];
        foreach ($this->keysForTab($tab) as $key => $meta) {
            $rules[$this->keyToField($key)] = match ($meta['type'] ?? 'float') {
                'int'              => ['required', 'integer'],
                'bool'             => ['required', 'boolean'],
                'string', 'secret' => ['nullable', 'string', 'max:500'],
                default            => ['required', 'numeric'],
            };
        }

        $data = $request->validate($rules);

        $values = [];
        foreach ($this->keysForTab($tab) as $key => $meta) {
            $raw  = $data[$this->keyToField($key)] ?? null;
            $type = $meta['type'] ?? 'float';

            // Secrets : le champ contient la valeur réelle (resoumise telle
            // quelle si inchangée) ; vider = supprimer, cf. _groups-form.
            $values[$key] = match ($type) {
                'int'    => (int) $raw,
                'bool'   => (bool) filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'string',
                'secret' => trim((string) ($raw ?? '')),
                default  => (float) $raw,
            };
        }

        $this->settings->setMany($values);

        return redirect()
            ->route('admin.settings.index', ['tab' => $tab])
            ->with('status', 'Paramètres « ' . self::CATEGORIES[$tab]['label'] . ' » enregistrés.');
    }

    /**
     * Groupes (avec leurs clés + valeurs courantes) de l'onglet, au format
     * attendu par le partial admin.settings._groups-form.
     *
     * @return array<string,array{title:string,icon:string,keys:array<string,mixed>}>
     */
    private function groupsForTab(string $tab): array
    {
        $values = $this->settings->all();

        $groups = [];
        foreach (self::CATEGORIES[$tab]['groups'] as $g) {
            $meta = self::GROUP_META[$g] ?? ['title' => ucfirst($g), 'icon' => 'fa-gear'];
            $groups[$g] = $meta + ['keys' => []];
        }

        foreach (Settings::DEFAULTS as $key => $meta) {
            $g = $meta['group'] ?? 'misc';
            if (($meta['type'] ?? 'float') === 'json' || ! isset($groups[$g])) {
                continue;
            }
            $groups[$g]['keys'][$key] = $meta + [
                'value'   => $values[$key] ?? $meta['default'],
                'default' => $meta['default'],
            ];
        }

        return $groups;
    }

    /** @return array<string,array<string,mixed>> clés de DEFAULTS couvertes par l'onglet */
    private function keysForTab(string $tab): array
    {
        $wanted = self::CATEGORIES[$tab]['groups'];

        $keys = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            if (in_array($meta['group'] ?? '', $wanted, true)) {
                $keys[$key] = $meta;
            }
        }
        return $keys;
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
