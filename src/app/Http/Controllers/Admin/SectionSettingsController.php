<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelVariableOverride;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class SectionSettingsController extends Controller
{
    private const METEO_TABS = [
        'consensus'     => ['label' => 'Consensus',         'icon' => 'fa-solid fa-scale-balanced'],
        'variables'     => ['label' => 'Variables',         'icon' => 'fa-solid fa-table-cells'],
        'dependencies'  => ['label' => 'Dépendances',       'icon' => 'fa-solid fa-diagram-project'],
        'sidecar'       => ['label' => 'État sidecar',      'icon' => 'fa-solid fa-satellite-dish'],
    ];

    /**
     * Variables éligibles au consensus (clés `consensus.config.<variable>`).
     */
    public const CONSENSUS_VARIABLES = [
        // Sol (12)
        'wind_speed_10m', 'wind_direction_10m', 'wind_gusts_10m',
        'temperature_2m', 'relative_humidity_2m', 'precipitation',
        'cloud_cover_low', 'cloud_cover_mid', 'cloud_cover_high',
        'shortwave_radiation', 'visibility', 'freezing_level_height',
        // Vent en altitude AGL (6)
        'wind_speed_80m', 'wind_direction_80m',
        'wind_speed_120m', 'wind_direction_120m',
        'wind_speed_180m', 'wind_direction_180m',
        // Niveau 850 hPa (5)
        'temperature_850hPa', 'wind_speed_850hPa', 'wind_direction_850hPa',
        'cloud_cover_850hPa', 'relative_humidity_850hPa',
        // Instabilité convective (3)
        'cape', 'convective_inhibition', 'convective_precipitation',
        // Pipeline dédié (1)
        'weather_code',
        // Dérivées Qui-Vole (3)
        'dew_point_2m', 'qui_vole_storm_risk', 'qui_vole_cloud_base',
    ];

    public function meteo(Request $request, Settings $settings): View
    {
        $tab  = $this->resolveMeteoTab($request);
        $data = [
            'tab'  => $tab,
            'tabs' => self::METEO_TABS,
        ];

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

        return view('admin.meteo.settings', $data);
    }

    /**
     * POST — save consensus config for all variables.
     */
    public function updateConsensus(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'vars'                => ['required', 'array'],
            'vars.*.method'       => ['required', 'string', 'in:A,B,derived,vote'],
            'vars.*.z_threshold'  => ['required', 'numeric', 'min:0.5', 'max:10'],
            'vars.*.mad_floor'    => ['required', 'numeric', 'min:0', 'max:100'],
            'vars.*.epsilon'      => ['required', 'numeric', 'gt:0', 'max:1'],
            'vars.*.use_weight_factor'    => ['nullable', 'boolean'],
            'vars.*.use_bias_correction'  => ['nullable', 'boolean'],
            'vars.*.use_mad_filtering'    => ['nullable', 'boolean'],
            'vars.*.use_weighted_median'  => ['nullable', 'boolean'],
            'vars.*.render_tiles'         => ['nullable', 'boolean'],
        ]);

        $values = [];

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
                'render_tiles'        => (bool) ($cfg['render_tiles'] ?? false),
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
        $values = [];

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
    // ── Tab resolution ──────────────────────────────────────────

    private function resolveMeteoTab(Request $request): string
    {
        $allowed = array_keys(self::METEO_TABS);

        return in_array($request->query('tab'), $allowed, true)
            ? $request->query('tab')
            : 'consensus';
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
