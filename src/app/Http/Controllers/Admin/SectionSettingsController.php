<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\ModelVariableOverride;
use App\Models\Site;
use App\Services\DataCoverage;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class SectionSettingsController extends Controller
{
    private const METEO_TABS = [
        'general'       => ['label' => 'Général',           'icon' => 'fa-solid fa-sliders'],
        'data'          => ['label' => 'Data',              'icon' => 'fa-solid fa-cloud-arrow-down'],
        'consensus'     => ['label' => 'Consensus',         'icon' => 'fa-solid fa-scale-balanced'],
        'orchestration' => ['label' => 'Orchestration',     'icon' => 'fa-solid fa-clock'],
        'variables'     => ['label' => 'Variables',         'icon' => 'fa-solid fa-table-cells'],
        'dependencies'  => ['label' => 'Dépendances',       'icon' => 'fa-solid fa-diagram-project'],
        'sidecar'       => ['label' => 'État sidecar',      'icon' => 'fa-solid fa-satellite-dish'],
        'logs'          => ['label' => 'Log / Monitoring',  'icon' => 'fa-solid fa-scroll'],
    ];

    /**
     * List of valid consensus variables (the 16 consensus-eligible vars).
     */
    public const CONSENSUS_VARIABLES = [
        'wind_speed_10m', 'wind_gusts_10m', 'wind_direction_10m',
        'temperature_2m', 'relative_humidity_2m', 'precipitation',
        'cloud_cover_low', 'cloud_cover_mid', 'cloud_cover_high',
        'temperature_850hPa', 'wind_speed_850hPa', 'wind_direction_850hPa',
        'cape', 'convective_inhibition', 'lifted_index',
        'convective_precipitation', 'boundary_layer_height',
    ];

    public function contenu(Request $request): View
    {
        return view('admin.contenu.settings', [
            'tab' => $this->resolveTab($request),
        ]);
    }

    public function meteo(Request $request, Settings $settings, DataCoverage $coverage): View
    {
        $tab  = $this->resolveMeteoTab($request);
        $data = [
            'tab'  => $tab,
            'tabs' => self::METEO_TABS,
        ];

        if ($tab === 'general') {
            $data['settingsGroups'] = $this->loadSettingsGroups(
                ['reliability'],
                $settings->all(),
            );
        }

        if ($tab === 'data') {
            $data['modelFreshness'] = $coverage->modelFreshness();
            $data['today']          = CarbonImmutable::now()->startOfDay();
        }

        if ($tab === 'consensus') {
            $allValues = $settings->all();
            $varConfigs = [];
            foreach (self::CONSENSUS_VARIABLES as $var) {
                $key = "consensus.config.{$var}";
                $meta = Settings::DEFAULTS[$key] ?? null;
                $varConfigs[$var] = [
                    'label'  => $meta['label'] ?? $var,
                    'config' => $allValues[$key] ?? $meta['default'] ?? [],
                ];
            }
            $data['varConfigs']     = $varConfigs;
            $data['defaultMethod']  = $allValues['consensus.global.default_method'] ?? 'B';
            $data['previewEnabled'] = (bool) ($allValues['consensus.global.preview_enabled'] ?? false);
            $data['renderTiles']    = (bool) ($allValues['consensus.global.render_tiles'] ?? false);
        }

        if ($tab === 'orchestration') {
            $allValues = $settings->all();
            $schedulerKeys = array_filter(
                Settings::DEFAULTS,
                fn ($meta) => ($meta['group'] ?? '') === 'consensus_scheduler'
            );
            $schedulerSettings = [];
            foreach ($schedulerKeys as $key => $meta) {
                $schedulerSettings[$key] = [
                    'value'   => $allValues[$key] ?? $meta['default'],
                    'label'   => $meta['label'],
                    'description' => $meta['description'],
                    'type'    => $meta['type'] ?? 'int',
                    'default' => $meta['default'],
                ];
            }
            $data['schedulerSettings'] = $schedulerSettings;
        }

        if ($tab === 'variables') {
            $overrides = ModelVariableOverride::all()
                ->keyBy(fn ($o) => "{$o->model_code}:{$o->variable}");
            $data['overrides'] = $overrides;
            $data['sidecarVariablesEndpoint'] = route('admin.meteo.sidecar.models-variables', [], false);
        }

        if ($tab === 'dependencies') {
            $data['sidecarDependencyEndpoint'] = route('admin.meteo.sidecar.dependency-graph', [], false);
        }

        if ($tab === 'sidecar') {
            $data['sidecarConfigEndpoint'] = route('admin.meteo.sidecar.active-config', [], false);
        }

        if ($tab === 'logs') {
            $data['runsRecentEndpoint'] = route('admin.meteo.sidecar.runs-recent', [], false);
            $data['runsStatsEndpoint']  = route('admin.meteo.sidecar.runs-stats', [], false);
        }

        return view('admin.meteo.settings', $data);
    }

    /**
     * POST — save consensus config for all variables.
     */
    public function updateConsensus(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'default_method'      => ['required', 'string', 'in:A,B'],
            'preview_enabled'     => ['nullable', 'boolean'],
            'render_tiles'        => ['nullable', 'boolean'],
            'vars'                => ['required', 'array'],
            'vars.*.method'       => ['required', 'string', 'in:A,B'],
            'vars.*.z_threshold'  => ['required', 'numeric', 'min:0.5', 'max:10'],
            'vars.*.mad_floor'    => ['required', 'numeric', 'min:0', 'max:100'],
            'vars.*.epsilon'      => ['required', 'numeric', 'gt:0', 'max:1'],
            'vars.*.use_weight_factor'    => ['nullable', 'boolean'],
            'vars.*.use_bias_correction'  => ['nullable', 'boolean'],
            'vars.*.use_mad_filtering'    => ['nullable', 'boolean'],
            'vars.*.use_weighted_median'  => ['nullable', 'boolean'],
        ]);

        $values = [
            'consensus.global.default_method'  => $data['default_method'],
            'consensus.global.preview_enabled' => (bool) ($data['preview_enabled'] ?? false),
            'consensus.global.render_tiles'    => (bool) ($data['render_tiles'] ?? false),
        ];

        foreach ($data['vars'] as $var => $cfg) {
            if (! in_array($var, self::CONSENSUS_VARIABLES, true)) {
                continue;
            }
            $values["consensus.config.{$var}"] = [
                'method'              => $cfg['method'],
                'use_weight_factor'   => (bool) ($cfg['use_weight_factor'] ?? false),
                'use_bias_correction' => (bool) ($cfg['use_bias_correction'] ?? false),
                'z_threshold'         => (float) $cfg['z_threshold'],
                'mad_floor'           => (float) $cfg['mad_floor'],
                'use_mad_filtering'   => (bool) ($cfg['use_mad_filtering'] ?? false),
                'use_weighted_median' => (bool) ($cfg['use_weighted_median'] ?? false),
                'epsilon'             => (float) $cfg['epsilon'],
            ];
        }

        $settings->setMany($values);

        return redirect()
            ->route('admin.meteo.settings', ['tab' => 'consensus'])
            ->with('status', 'Configuration consensus enregistrée.');
    }

    /**
     * POST — restore all consensus settings to their factory defaults.
     */
    public function restoreConsensusDefaults(Settings $settings): RedirectResponse
    {
        $values = [
            'consensus.global.default_method'  => Settings::DEFAULTS['consensus.global.default_method']['default'],
            'consensus.global.preview_enabled' => Settings::DEFAULTS['consensus.global.preview_enabled']['default'],
            'consensus.global.render_tiles'    => Settings::DEFAULTS['consensus.global.render_tiles']['default'],
        ];

        foreach (self::CONSENSUS_VARIABLES as $var) {
            $key = "consensus.config.{$var}";
            $values[$key] = Settings::DEFAULTS[$key]['default'];
        }

        $settings->setMany($values);

        return redirect()
            ->route('admin.meteo.settings', ['tab' => 'consensus'])
            ->with('status', 'Configuration consensus restaurée aux valeurs par défaut.');
    }

    /**
     * POST — save orchestration/scheduler settings.
     */
    public function updateOrchestration(Request $request, Settings $settings): RedirectResponse
    {
        $rules = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['group'] ?? '') !== 'consensus_scheduler') {
                continue;
            }
            $field = str_replace('.', '__', $key);
            $rules[$field] = match ($meta['type'] ?? 'int') {
                'string' => ['required', 'string', 'in:cron,event_driven'],
                default  => ['required', 'integer', 'min:0'],
            };
        }

        $data = $request->validate($rules);

        $values = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['group'] ?? '') !== 'consensus_scheduler') {
                continue;
            }
            $field = str_replace('.', '__', $key);
            $raw = $data[$field] ?? $meta['default'];
            $values[$key] = ($meta['type'] ?? 'int') === 'string'
                ? (string) $raw
                : (int) $raw;
        }

        $settings->setMany($values);

        return redirect()
            ->route('admin.meteo.settings', ['tab' => 'orchestration'])
            ->with('status', 'Paramètres d\'orchestration enregistrés.');
    }

    /**
     * POST — toggle a model variable override.
     */
    public function updateVariableOverride(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'model_code'  => ['required', 'string', 'max:64'],
            'variable'    => ['required', 'string', 'max:64'],
            'enabled'     => ['required', 'boolean'],
            'notes_admin' => ['nullable', 'string', 'max:2000'],
        ]);

        ModelVariableOverride::updateOrCreate(
            ['model_code' => $data['model_code'], 'variable' => $data['variable']],
            ['enabled' => $data['enabled'], 'notes_admin' => $data['notes_admin']],
        );

        return redirect()
            ->route('admin.meteo.settings', ['tab' => 'variables'])
            ->with('status', "Override {$data['variable']} pour {$data['model_code']} enregistré.");
    }

    public function sites(Request $request, Settings $settings, DataCoverage $coverage): View
    {
        $tab  = $this->resolveTab($request);
        $data = [
            'tab'        => $tab,
            'countries'  => DataSyncController::ISO_COUNTRIES,
            'sitesTotal' => Site::count(),
            'sitesPge'   => Site::where('source', 'paraglidingearth')->count(),
        ];

        if ($tab === 'general') {
            $data['settingsGroups'] = $this->loadSettingsGroups(
                ['precip', 'gust', 'viability', 'quality'],
                $settings->all(),
            );
        }

        if ($tab === 'data') {
            $data['siteForecasts'] = $coverage->siteForecastCoverage();
            $data['today']         = CarbonImmutable::now()->startOfDay();
        }

        return view('admin.sites.settings', $data);
    }

    public function updateSiteSettings(Request $request, Settings $settings): RedirectResponse
    {
        $this->saveSettingsGroups($request, $settings, ['precip', 'gust', 'viability', 'quality']);

        return redirect()
            ->route('admin.sites.settings', ['tab' => 'general'])
            ->with('status', 'Paramètres des sites enregistrés.');
    }

    public function weatherStations(Request $request, Settings $settings, DataCoverage $coverage): View
    {
        $tab  = $this->resolveTab($request);

        $stationApis = \App\Models\StationApi::all()->keyBy('code');
        $data = [
            'tab'              => $tab,
            'mfKeyConfigured'  => $stationApis->get('mf')?->hasOAuth2Credentials() ?? false,
            'icKeyConfigured'  => ! empty($stationApis->get('infoclimat')?->api_key),
        ];

        if ($tab === 'general') {
            $data['settingsGroups'] = $this->loadSettingsGroups(
                ['stations'],
                $settings->all(),
            );
        }

        if ($tab === 'data') {
            $data['bbox'] = DataSyncController::DEFAULT_BBOX;
            $data['stationApis'] = $stationApis;
            $data['stationCounts'] = [
                'mf'         => \App\Models\WeatherStation::where('network', 'mf')->count(),
                'metar'      => \App\Models\WeatherStation::where('network', 'metar')->count(),
                'infoclimat' => \App\Models\WeatherStation::where('network', 'infoclimat')->count(),
            ];
            $data['stationForecasts'] = $coverage->stationForecastCoverage();
            $data['stationReadings']  = $coverage->stationReadingsCoverage();
            $data['today']            = CarbonImmutable::now()->startOfDay();
        }

        return view('admin.weather-stations.settings', $data);
    }

    public function updateWeatherStationSettings(Request $request, Settings $settings): RedirectResponse
    {
        $this->saveSettingsGroups($request, $settings, ['stations']);

        return redirect()
            ->route('admin.weather-stations.settings', ['tab' => 'general'])
            ->with('status', 'Paramètres des stations météo enregistrés.');
    }

    public function balises(Request $request, Settings $settings, DataCoverage $coverage): View
    {
        $tab  = $this->resolveTab($request);
        $data = [
            'tab'                => $tab,
            'bbox'               => DataSyncController::DEFAULT_BBOX,
            'balisesPiou'        => Balise::where('source', 'pioupiou')->count(),
            'balisesWindy'       => Balise::where('source', 'windy')->count(),
            'windyKeyConfigured' => trim((string) $settings->get('windy.api_key', '')) !== '',
        ];

        if ($tab === 'general') {
            $data['settingsGroups'] = $this->loadSettingsGroups(
                ['balises'],
                $settings->all(),
            );
        }

        if ($tab === 'data') {
            $data['baliseForecasts'] = $coverage->baliseForecastCoverage();
            $data['baliseReadings']  = $coverage->baliseReadingsCoverage();
            $data['today']           = CarbonImmutable::now()->startOfDay();
        }

        return view('admin.balises.settings', $data);
    }

    public function updateBaliseSettings(Request $request, Settings $settings): RedirectResponse
    {
        $this->saveSettingsGroups($request, $settings, ['balises']);

        return redirect()
            ->route('admin.balises.settings', ['tab' => 'general'])
            ->with('status', 'Paramètres des balises enregistrés.');
    }

    public function updateMeteoGeneralSettings(Request $request, Settings $settings): RedirectResponse
    {
        $this->saveSettingsGroups($request, $settings, ['reliability']);

        return redirect()
            ->route('admin.meteo.settings', ['tab' => 'general'])
            ->with('status', 'Paramètres de fiabilité enregistrés.');
    }

    // ── Helpers settings groupés ─────────────────────────────────

    /**
     * Construit le tableau $settingsGroups pour les groupes demandés,
     * en lisant les valeurs courantes depuis Settings::DEFAULTS + $values.
     *
     * @param  string[] $groupKeys  Groupes à inclure (ex: ['precip', 'gust'])
     * @param  array    $values     Résultat de Settings::all()
     * @return array
     */
    private function loadSettingsGroups(array $groupKeys, array $values): array
    {
        $definitions = [
            'precip'      => ['title' => 'Précipitations',           'icon' => 'fa-cloud-rain'],
            'gust'        => ['title' => 'Rafales',                   'icon' => 'fa-tornado'],
            'viability'   => ['title' => "Viabilité d'une journée",  'icon' => 'fa-chart-line'],
            'quality'     => ['title' => 'Qualité des données',       'icon' => 'fa-clipboard-check'],
            'balises'     => ['title' => 'Sources balises',           'icon' => 'fa-tower-broadcast'],
            'stations'    => ['title' => 'Stations météo',              'icon' => 'fa-tower-broadcast'],
            'reliability' => ['title' => 'Fiabilité des modèles',     'icon' => 'fa-flask-vial'],
        ];

        $groups = [];
        foreach ($groupKeys as $gk) {
            $groups[$gk] = ($definitions[$gk] ?? ['title' => $gk, 'icon' => 'fa-gear']) + ['keys' => []];
        }

        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            $g = $meta['group'] ?? '';
            if (! isset($groups[$g])) {
                continue;
            }
            $groups[$g]['keys'][$key] = $meta + ['value' => $values[$key] ?? $meta['default']];
        }

        return array_filter($groups, fn ($g) => ! empty($g['keys']));
    }

    /**
     * Valide et sauvegarde les settings appartenant aux groupes donnés.
     *
     * @param  string[] $groupKeys
     */
    private function saveSettingsGroups(Request $request, Settings $settings, array $groupKeys): void
    {
        $rules = [];
        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            if (! in_array($meta['group'] ?? '', $groupKeys, true)) {
                continue;
            }
            $field          = str_replace('.', '__', $key);
            $rules[$field]  = match ($meta['type'] ?? 'float') {
                'int'              => ['required', 'integer', 'min:0'],
                'bool'             => ['required', 'boolean'],
                'string', 'secret' => ['nullable', 'string', 'max:500'],
                default            => ['required', 'numeric', 'min:0'],
            };
        }

        $data   = $request->validate($rules);
        $values = [];

        foreach (Settings::DEFAULTS as $key => $meta) {
            if (($meta['type'] ?? 'float') === 'json') {
                continue;
            }
            if (! in_array($meta['group'] ?? '', $groupKeys, true)) {
                continue;
            }
            $field  = str_replace('.', '__', $key);
            $raw    = $data[$field] ?? null;
            $type   = $meta['type'] ?? 'float';

            $values[$key] = match ($type) {
                'int'              => (int) $raw,
                'bool'             => (bool) filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'string', 'secret' => trim((string) ($raw ?? '')),
                default            => (float) $raw,
            };
        }

        $settings->setMany($values);
    }

    // ── Tab resolution ──────────────────────────────────────────

    private function resolveTab(Request $request): string
    {
        $allowed = ['general', 'data', 'logs'];

        return in_array($request->query('tab'), $allowed, true)
            ? $request->query('tab')
            : 'general';
    }

    private function resolveMeteoTab(Request $request): string
    {
        $allowed = array_keys(self::METEO_TABS);

        return in_array($request->query('tab'), $allowed, true)
            ? $request->query('tab')
            : 'general';
    }

    private function sidecarBaseUrl(): string
    {
        return rtrim(config('services.consensus_grid.base_url', 'http://parapente-consensus-grid-v2-api:8082'), '/') . '/v1';
    }

    /**
     * Proxy GET — /v1/consensus/active_config (sidecar interne, injoignable depuis le navigateur).
     */
    public function sidecarActiveConfig(): \Illuminate\Http\JsonResponse
    {
        return $this->proxySidecar('/consensus/active_config');
    }

    /**
     * Proxy GET — /v1/consensus/dependency_graph.
     */
    public function sidecarDependencyGraph(): \Illuminate\Http\JsonResponse
    {
        return $this->proxySidecar('/consensus/dependency_graph');
    }

    /**
     * Proxy GET — /v1/models/variables.
     */
    public function sidecarModelsVariables(): \Illuminate\Http\JsonResponse
    {
        return $this->proxySidecar('/models/variables');
    }

    /**
     * Proxy GET — /v1/runs/recent?limit=N.
     */
    public function sidecarRunsRecent(Request $request): \Illuminate\Http\JsonResponse
    {
        $limit = min((int) $request->query('limit', 10), 50);
        return $this->proxySidecar("/runs/recent?limit={$limit}");
    }

    /**
     * Proxy GET — /v1/runs/stats?window=N.
     */
    public function sidecarRunsStats(Request $request): \Illuminate\Http\JsonResponse
    {
        $window = min((int) $request->query('window', 20), 100);
        return $this->proxySidecar("/runs/stats?window={$window}");
    }

    private function proxySidecar(string $path): \Illuminate\Http\JsonResponse
    {
        $timeout = (int) config('services.consensus_grid.timeout', 10);
        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($this->sidecarBaseUrl() . $path);

            if ($response->status() === 404) {
                return response()->json([
                    'error'   => true,
                    'message' => "Endpoint {$path} non disponible sur cette version du sidecar.",
                ], 200);
            }

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => true,
                'message' => 'Sidecar injoignable : ' . $e->getMessage(),
            ], 502);
        }
    }
}
