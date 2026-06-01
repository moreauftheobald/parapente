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
            $data['sidecarVariablesEndpoint'] = route('admin.meteo.sidecar.models-variables');
        }

        if ($tab === 'dependencies') {
            $data['sidecarDependencyEndpoint'] = route('admin.meteo.sidecar.dependency-graph');
        }

        if ($tab === 'sidecar') {
            $data['sidecarConfigEndpoint'] = route('admin.meteo.sidecar.active-config');
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
        return rtrim(config('services.consensus_grid.base_url', 'http://consensus-grid:8082'), '/') . '/v1';
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

    private function proxySidecar(string $path): \Illuminate\Http\JsonResponse
    {
        $timeout = (int) config('services.consensus_grid.timeout', 10);
        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($this->sidecarBaseUrl() . $path);

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => true,
                'message' => 'Sidecar injoignable : ' . $e->getMessage(),
            ], 502);
        }
    }
}
