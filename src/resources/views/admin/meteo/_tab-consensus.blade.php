@php
    $inputCls = 'w-full px-2.5 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 font-mono focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $categories = [
        'Sol (10 m / 2 m)' => ['wind_speed_10m', 'wind_direction_10m', 'wind_gusts_10m', 'temperature_2m', 'relative_humidity_2m', 'precipitation', 'cloud_cover_low', 'cloud_cover_mid', 'cloud_cover_high', 'shortwave_radiation', 'visibility', 'freezing_level_height'],
        'Vent en altitude AGL'  => ['wind_speed_80m', 'wind_direction_80m', 'wind_speed_120m', 'wind_direction_120m', 'wind_speed_180m', 'wind_direction_180m'],
        'Niveau 850 hPa'       => ['temperature_850hPa', 'wind_speed_850hPa', 'wind_direction_850hPa', 'cloud_cover_850hPa', 'relative_humidity_850hPa'],
        'Instabilité convective' => ['cape', 'convective_inhibition', 'convective_precipitation'],
        'Pipeline dédié'        => ['weather_code'],
        'Dérivées Qui-Vole'    => ['dew_point_2m', 'qui_vole_storm_risk', 'qui_vole_cloud_base'],
    ];
@endphp

@if ($errors->any())
    <x-admin.alert type="error">
        <ul class="list-disc list-inside">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-admin.alert>
@endif

<form method="POST" action="{{ route('admin.meteo.settings.consensus') }}">
    @csrf

    {{-- Defaults globaux ──────────────────────────────────────── --}}
    <x-admin.section title="Defaults globaux" icon="fa-solid fa-sliders" color="sky" class="mb-6">
        <div class="flex items-center gap-6 flex-wrap">
            <x-admin.field name="default_method" label="Méthode par défaut"
                           hint="Méthode appliquée aux variables sans config explicite. A = legacy inverse-carré, B = amélioré MAD/médiane.">
                <select name="default_method" class="{{ $inputCls }} w-32">
                    <option value="A" @selected(old('default_method', $defaultMethod) === 'A')>A (legacy)</option>
                    <option value="B" @selected(old('default_method', $defaultMethod) === 'B')>B (amélioré)</option>
                </select>
            </x-admin.field>
            <div>
                <input type="hidden" name="preview_enabled" value="0">
                <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-400">
                    <input type="checkbox" name="preview_enabled" value="1"
                           @checked(old('preview_enabled', $previewEnabled))
                           class="h-4 w-4 rounded border-gray-700 bg-gray-800 text-sky-500 focus:ring-sky-500/30">
                    Endpoint /preview actif
                    <x-admin.tooltip text="Active l'endpoint /v1/consensus/preview côté sidecar (debug)." />
                </label>
            </div>
        </div>
    </x-admin.section>

    {{-- Config par variable ────────────────────────────────────── --}}
    @foreach ($categories as $catLabel => $catVars)
        <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3 mt-6">
            <i class="fa-solid fa-layer-group text-gray-600"></i> {{ $catLabel }}
        </h2>

        <div class="space-y-3 mb-6">
            @foreach ($catVars as $var)
                @php
                    $vc = $varConfigs[$var] ?? null;
                    if (! $vc) continue;
                    $cfg = $vc['config'];
                    $lbl = $vc['label'];
                    $isDerived = in_array($cfg['method'] ?? 'B', ['derived', 'vote']);
                @endphp
                <div class="bg-gray-900 border border-gray-800 rounded-xl p-4"
                     x-data="{ method: '{{ old("vars.{$var}.method", $cfg['method'] ?? 'B') }}' }">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="text-sm font-medium text-white">{{ $lbl }}</span>
                        <span class="text-[10px] font-mono text-gray-600 px-1.5 py-0.5 bg-gray-950 rounded border border-gray-800">{{ $var }}</span>
                        @if ($isDerived)
                            <span class="text-[10px] px-1.5 py-0.5 rounded border bg-violet-500/15 text-violet-300 border-violet-500/30">{{ $cfg['method'] === 'vote' ? 'vote WMO' : 'dérivée' }}</span>
                        @endif
                    </div>

                    @if ($isDerived)
                        {{-- Variables dérivées / vote : seul render_tiles est configurable --}}
                        <input type="hidden" name="vars[{{ $var }}][method]" value="{{ $cfg['method'] }}">
                        <input type="hidden" name="vars[{{ $var }}][use_weight_factor]" value="0">
                        <input type="hidden" name="vars[{{ $var }}][use_bias_correction]" value="0">
                        <input type="hidden" name="vars[{{ $var }}][use_mad_filtering]" value="0">
                        <input type="hidden" name="vars[{{ $var }}][use_weighted_median]" value="0">
                        <input type="hidden" name="vars[{{ $var }}][z_threshold]" value="{{ $cfg['z_threshold'] ?? 3.0 }}">
                        <input type="hidden" name="vars[{{ $var }}][mad_floor]" value="{{ $cfg['mad_floor'] ?? 0.5 }}">
                        <input type="hidden" name="vars[{{ $var }}][epsilon]" value="{{ $cfg['epsilon'] ?? 0.001 }}">
                        <div class="flex items-center gap-4">
                            <input type="hidden" name="vars[{{ $var }}][render_tiles]" value="0">
                            <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-400">
                                <input type="checkbox" name="vars[{{ $var }}][render_tiles]" value="1"
                                       @checked(old("vars.{$var}.render_tiles", $cfg['render_tiles'] ?? false))
                                       class="h-3.5 w-3.5 rounded border-gray-700 bg-gray-800 text-emerald-500 focus:ring-emerald-500/30">
                                Render tiles
                            </label>
                            <span class="text-[10px] text-gray-600">Les autres paramètres ne s'appliquent pas à cette variable.</span>
                        </div>
                    @else
                    <div class="flex flex-wrap items-center gap-4">
                        {{-- Méthode --}}
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-gray-500">Méthode</span>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="radio" name="vars[{{ $var }}][method]" value="A"
                                       x-model="method"
                                       @checked(old("vars.{$var}.method", $cfg['method'] ?? 'B') === 'A')
                                       class="text-sky-500 border-gray-700 bg-gray-800 focus:ring-sky-500/30">
                                <span class="text-xs text-gray-300">A</span>
                            </label>
                            <label class="flex items-center gap-1 cursor-pointer">
                                <input type="radio" name="vars[{{ $var }}][method]" value="B"
                                       x-model="method"
                                       @checked(old("vars.{$var}.method", $cfg['method'] ?? 'B') === 'B')
                                       class="text-sky-500 border-gray-700 bg-gray-800 focus:ring-sky-500/30">
                                <span class="text-xs text-gray-300">B</span>
                            </label>
                        </div>

                        {{-- Toggles --}}
                        <input type="hidden" name="vars[{{ $var }}][use_weight_factor]" value="0">
                        <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-400">
                            <input type="checkbox" name="vars[{{ $var }}][use_weight_factor]" value="1"
                                   @checked(old("vars.{$var}.use_weight_factor", $cfg['use_weight_factor'] ?? false))
                                   class="h-3.5 w-3.5 rounded border-gray-700 bg-gray-800 text-amber-500 focus:ring-amber-500/30">
                            Weight
                        </label>
                        <input type="hidden" name="vars[{{ $var }}][use_bias_correction]" value="0">
                        <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-400">
                            <input type="checkbox" name="vars[{{ $var }}][use_bias_correction]" value="1"
                                   @checked(old("vars.{$var}.use_bias_correction", $cfg['use_bias_correction'] ?? false))
                                   class="h-3.5 w-3.5 rounded border-gray-700 bg-gray-800 text-amber-500 focus:ring-amber-500/30">
                            Bias corr.
                        </label>

                        <span class="text-gray-700">|</span>

                        <input type="hidden" name="vars[{{ $var }}][render_tiles]" value="0">
                        <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-400">
                            <input type="checkbox" name="vars[{{ $var }}][render_tiles]" value="1"
                                   @checked(old("vars.{$var}.render_tiles", $cfg['render_tiles'] ?? false))
                                   class="h-3.5 w-3.5 rounded border-gray-700 bg-gray-800 text-emerald-500 focus:ring-emerald-500/30">
                            Render tiles
                        </label>

                        {{-- Méthode B : MAD + médiane --}}
                        <template x-if="method === 'B'">
                            <div class="flex items-center gap-4 flex-wrap">
                                <input type="hidden" name="vars[{{ $var }}][use_mad_filtering]" value="0">
                                <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-400">
                                    <input type="checkbox" name="vars[{{ $var }}][use_mad_filtering]" value="1"
                                           @checked(old("vars.{$var}.use_mad_filtering", $cfg['use_mad_filtering'] ?? true))
                                           class="h-3.5 w-3.5 rounded border-gray-700 bg-gray-800 text-sky-500 focus:ring-sky-500/30">
                                    MAD filter
                                </label>
                                <input type="hidden" name="vars[{{ $var }}][use_weighted_median]" value="0">
                                <label class="flex items-center gap-1.5 cursor-pointer text-xs text-gray-400">
                                    <input type="checkbox" name="vars[{{ $var }}][use_weighted_median]" value="1"
                                           @checked(old("vars.{$var}.use_weighted_median", $cfg['use_weighted_median'] ?? true))
                                           class="h-3.5 w-3.5 rounded border-gray-700 bg-gray-800 text-sky-500 focus:ring-sky-500/30">
                                    Médiane pond.
                                </label>
                                <div class="flex items-center gap-1">
                                    <span class="text-[10px] text-gray-500">z</span>
                                    <input type="number" name="vars[{{ $var }}][z_threshold]"
                                           value="{{ old("vars.{$var}.z_threshold", $cfg['z_threshold'] ?? 3.0) }}"
                                           step="0.1" min="0.5" max="10"
                                           class="{{ $inputCls }} w-16 text-xs">
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="text-[10px] text-gray-500">MAD floor</span>
                                    <input type="number" name="vars[{{ $var }}][mad_floor]"
                                           value="{{ old("vars.{$var}.mad_floor", $cfg['mad_floor'] ?? 0.5) }}"
                                           step="0.1" min="0" max="100"
                                           class="{{ $inputCls }} w-16 text-xs">
                                </div>
                            </div>
                        </template>
                        {{-- Hidden fields for method B params when A is selected --}}
                        <template x-if="method === 'A'">
                            <div class="flex items-center gap-4 flex-wrap">
                                <input type="hidden" name="vars[{{ $var }}][use_mad_filtering]" value="0">
                                <input type="hidden" name="vars[{{ $var }}][use_weighted_median]" value="0">
                                <input type="hidden" name="vars[{{ $var }}][z_threshold]" value="{{ $cfg['z_threshold'] ?? 3.0 }}">
                                <input type="hidden" name="vars[{{ $var }}][mad_floor]" value="{{ $cfg['mad_floor'] ?? 0.5 }}">
                                <div class="flex items-center gap-1">
                                    <span class="text-[10px] text-gray-500">epsilon</span>
                                    <input type="number" name="vars[{{ $var }}][epsilon]"
                                           value="{{ old("vars.{$var}.epsilon", $cfg['epsilon'] ?? 0.001) }}"
                                           step="0.001" min="0.001" max="1"
                                           class="{{ $inputCls }} w-20 text-xs">
                                </div>
                            </div>
                        </template>
                        {{-- Hidden epsilon for method B --}}
                        <template x-if="method === 'B'">
                            <input type="hidden" name="vars[{{ $var }}][epsilon]" value="{{ $cfg['epsilon'] ?? 0.001 }}">
                        </template>
                    </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach

    <div class="flex items-center justify-between mt-6">
        <x-admin.button type="submit" variant="primary" icon="fa-solid fa-floppy-disk">
            Enregistrer la configuration consensus
        </x-admin.button>
    </div>
</form>

{{-- Restauration des valeurs par défaut (formulaire séparé, confirmation JS) --}}
<div class="mt-4 pt-4 border-t border-gray-800">
    <form method="POST" action="{{ route('admin.meteo.settings.consensus.restore') }}"
          onsubmit="return confirm('Restaurer toutes les variables consensus aux valeurs par défaut ?\n\nCette action est irréversible (mais tracée dans l\'historique).')">
        @csrf
        <x-admin.button type="submit" variant="ghost" size="sm" icon="fa-solid fa-rotate-left">
            Restaurer les defaults métier
        </x-admin.button>
    </form>
</div>

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush
