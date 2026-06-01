{{-- Onglet État sidecar — affiche /v1/consensus/active_config --}}

<div x-data="sidecarPanel()" x-init="loadConfig()">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xs uppercase tracking-wider text-gray-400">
            <i class="fa-solid fa-satellite-dish text-sky-400"></i>
            Configuration active du sidecar
        </h2>
        <button type="button" @click="loadConfig()"
                :disabled="loading"
                class="px-3 py-1.5 rounded text-xs border border-sky-500/40 text-sky-300 hover:bg-sky-500/15 disabled:opacity-40 transition">
            <i class="fa-solid" :class="loading ? 'fa-spinner fa-spin' : 'fa-arrows-rotate'"></i>
            Rafraîchir
        </button>
    </div>

    <div x-show="loading && !config" class="text-center py-12 text-gray-500">
        <i class="fa-solid fa-spinner fa-spin text-2xl"></i>
        <p class="mt-2 text-sm">Connexion au sidecar…</p>
    </div>

    <div x-show="error && !config" x-cloak>
        <x-admin.alert type="warning">
            <span x-text="error"></span>
        </x-admin.alert>
        <x-admin.empty-state icon="fa-solid fa-satellite-dish" message="Le sidecar n'est pas joignable. Vérifiez que le conteneur consensus-grid est en ligne sur meteo-net." />
    </div>

    <div x-show="config" x-cloak class="space-y-4">
        {{-- Métadonnées --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="text-gray-500 text-xs">Chargé le</span>
                    <div class="text-white font-mono text-xs" x-text="config?.loaded_at || '—'"></div>
                </div>
                <div>
                    <span class="text-gray-500 text-xs">Source consensus</span>
                    <div class="text-white font-mono text-xs" x-text="config?.consensus_source || '—'"></div>
                </div>
                <div>
                    <span class="text-gray-500 text-xs">Méthode par défaut</span>
                    <div class="font-mono text-xs">
                        <span class="px-1.5 py-0.5 rounded border"
                              :class="config?.default_method === 'B'
                                  ? 'bg-sky-500/15 text-sky-300 border-sky-500/30'
                                  : 'bg-gray-800 text-gray-300 border-gray-700'"
                              x-text="config?.default_method || '—'"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Modèles actifs --}}
        <div x-show="config?.models_active?.length" class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 mb-2">Modèles actifs</h3>
            <div class="flex flex-wrap gap-1">
                <template x-for="m in (config?.models_active || [])" :key="m">
                    <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-300 border border-emerald-500/20" x-text="m"></span>
                </template>
            </div>
        </div>

        {{-- Weight factors --}}
        <div x-show="config?.models_weight_factor" class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 mb-2">Weight factors par modèle</h3>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2">
                <template x-for="(wf, code) in (config?.models_weight_factor || {})" :key="code">
                    <div class="flex items-center justify-between px-2 py-1 rounded bg-gray-950 border border-gray-800 text-[11px]">
                        <span class="font-mono text-gray-300 truncate" x-text="code"></span>
                        <span class="font-mono ml-2"
                              :class="wf === 1.0 ? 'text-gray-500' : (wf < 1.0 ? 'text-amber-300' : 'text-emerald-300')"
                              x-text="wf"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Config par variable --}}
        <div x-show="config?.per_variable" class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 px-4 pt-3 pb-2">Config consensus par variable (vue sidecar)</h3>
            <table class="w-full text-[11px]">
                <thead class="text-gray-500 border-b border-gray-800">
                    <tr>
                        <th class="text-left px-3 py-1.5 font-medium">Variable</th>
                        <th class="text-center px-2 py-1.5 font-medium">Méthode</th>
                        <th class="text-center px-2 py-1.5 font-medium">Weight</th>
                        <th class="text-center px-2 py-1.5 font-medium">Bias</th>
                        <th class="text-center px-2 py-1.5 font-medium">MAD</th>
                        <th class="text-center px-2 py-1.5 font-medium">Médiane</th>
                        <th class="text-right px-2 py-1.5 font-medium">z</th>
                        <th class="text-right px-2 py-1.5 font-medium">MAD floor</th>
                        <th class="text-right px-2 py-1.5 font-medium">epsilon</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/50">
                    <template x-for="(vc, varName) in (config?.per_variable || {})" :key="varName">
                        <tr class="hover:bg-gray-800/30 transition">
                            <td class="px-3 py-1.5 font-mono text-gray-300" x-text="varName"></td>
                            <td class="px-2 py-1.5 text-center">
                                <span class="px-1 py-0.5 rounded border"
                                      :class="vc.method === 'B' ? 'bg-sky-500/15 text-sky-300 border-sky-500/30' : 'bg-gray-800 text-gray-400 border-gray-700'"
                                      x-text="vc.method"></span>
                            </td>
                            <td class="px-2 py-1.5 text-center" x-text="vc.use_weight_factor ? '✓' : '—'"
                                :class="vc.use_weight_factor ? 'text-emerald-300' : 'text-gray-600'"></td>
                            <td class="px-2 py-1.5 text-center" x-text="vc.use_bias_correction ? '✓' : '—'"
                                :class="vc.use_bias_correction ? 'text-emerald-300' : 'text-gray-600'"></td>
                            <td class="px-2 py-1.5 text-center" x-text="vc.use_mad_filtering ? '✓' : '—'"
                                :class="vc.use_mad_filtering ? 'text-sky-300' : 'text-gray-600'"></td>
                            <td class="px-2 py-1.5 text-center" x-text="vc.use_weighted_median ? '✓' : '—'"
                                :class="vc.use_weighted_median ? 'text-sky-300' : 'text-gray-600'"></td>
                            <td class="px-2 py-1.5 text-right font-mono text-gray-400" x-text="vc.z_threshold"></td>
                            <td class="px-2 py-1.5 text-right font-mono text-gray-400" x-text="vc.mad_floor"></td>
                            <td class="px-2 py-1.5 text-right font-mono text-gray-400" x-text="vc.epsilon"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Scheduler --}}
        <div x-show="config?.scheduler" class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 mb-2">Scheduler</h3>
            <div class="flex gap-4 text-sm">
                <div>
                    <span class="text-gray-500 text-xs">Mode</span>
                    <div class="font-mono text-xs text-white" x-text="config?.scheduler?.mode || '—'"></div>
                </div>
                <div x-show="config?.scheduler?.cron_minute !== undefined">
                    <span class="text-gray-500 text-xs">Cron minute</span>
                    <div class="font-mono text-xs text-white" x-text="config?.scheduler?.cron_minute"></div>
                </div>
            </div>
        </div>

        <p class="text-xs text-gray-500">
            <i class="fa-solid fa-circle-info text-gray-600"></i>
            Cette vue reflète la configuration <strong>réellement chargée</strong> par le dernier run du sidecar.
            Les changements depuis l'onglet Consensus sont lus à chaque nouveau run.
        </p>
    </div>
</div>

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

@push('scripts')
<script>
function sidecarPanel() {
    return {
        loading: false,
        error: null,
        config: null,

        async loadConfig() {
            this.loading = true;
            this.error = null;
            try {
                const r = await fetch(@js($sidecarConfigEndpoint), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: AbortSignal.timeout(10000),
                });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const data = await r.json();
                if (data.error) throw new Error(data.message || 'Erreur sidecar');
                this.config = data;
            } catch (e) {
                this.error = 'Sidecar injoignable : ' + e.message;
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endpush
