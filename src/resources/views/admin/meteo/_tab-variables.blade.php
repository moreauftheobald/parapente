{{-- Onglet Variables — overrides par modèle.
     Charge les données depuis le sidecar /v1/models/variables via AJAX.
     Fallback : affiche les overrides en base si le sidecar est injoignable. --}}

<div x-data="variablesPanel()" x-init="loadData()">
    {{-- Loading / error state --}}
    <div x-show="loading" class="text-center py-12 text-gray-500">
        <i class="fa-solid fa-spinner fa-spin text-2xl"></i>
        <p class="mt-2 text-sm">Chargement des variables depuis le sidecar…</p>
    </div>

    <div x-show="error && !loading" x-cloak>
        <x-admin.alert type="warning">
            <span x-text="error"></span>
            <span class="block mt-1 text-xs">Les overrides en base sont affichés ci-dessous. Les colonnes « Disque » et « Effective » ne sont disponibles que si le sidecar est joignable.</span>
        </x-admin.alert>
    </div>

    {{-- Variables table --}}
    <div x-show="!loading" x-cloak>
        <template x-for="(modelData, modelCode) in models" :key="modelCode">
            <div class="mb-6">
                <div class="flex items-center gap-3 mb-2 cursor-pointer select-none"
                     @click="modelData._open = !modelData._open">
                    <i class="fa-solid fa-chevron-right w-3 text-gray-400 transition-transform"
                       :class="modelData._open && 'rotate-90'"></i>
                    <span class="text-sm font-medium text-white" x-text="modelCode"></span>
                    <span class="text-xs text-gray-500"
                          x-text="modelData.active_in_db ? 'actif' : 'inactif'"
                          :class="modelData.active_in_db ? 'text-emerald-400' : 'text-gray-600'"></span>
                    <span class="text-[10px] text-gray-600 font-mono"
                          x-text="'weight: ' + (modelData.weight_factor ?? 1.0)"></span>
                </div>

                <div x-show="modelData._open" x-cloak class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                            <tr>
                                <th class="text-left px-3 py-2 font-medium">Variable</th>
                                <th class="text-center px-3 py-2 font-medium">Disque</th>
                                <th class="text-center px-3 py-2 font-medium">Override admin</th>
                                <th class="text-center px-3 py-2 font-medium">Effective</th>
                                <th class="text-left px-3 py-2 font-medium">Notes</th>
                                <th class="text-center px-3 py-2 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800/70">
                            <template x-for="(varData, varName) in modelData.variables" :key="varName">
                                <tr class="hover:bg-gray-800/30 transition">
                                    <td class="px-3 py-2 font-mono text-xs text-gray-300" x-text="varName"></td>
                                    <td class="px-3 py-2 text-center">
                                        <template x-if="varData.auto_detected !== undefined">
                                            <i :class="varData.auto_detected ? 'fa-solid fa-circle-check text-emerald-400' : 'fa-solid fa-circle-xmark text-gray-600'"></i>
                                        </template>
                                        <template x-if="varData.auto_detected === undefined">
                                            <span class="text-gray-700">—</span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <template x-if="varData.override_enabled === null || varData.override_enabled === undefined">
                                            <span class="text-[10px] text-gray-600">(auto)</span>
                                        </template>
                                        <template x-if="varData.override_enabled === true">
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">forcé actif</span>
                                        </template>
                                        <template x-if="varData.override_enabled === false">
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/15 text-red-300 border border-red-500/30">désactivé</span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <template x-if="varData.effective !== undefined">
                                            <i :class="varData.effective ? 'fa-solid fa-circle-check text-emerald-400' : 'fa-solid fa-circle-xmark text-red-400'"></i>
                                        </template>
                                        <template x-if="varData.effective === undefined">
                                            <span class="text-gray-700">—</span>
                                        </template>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-500" x-text="varData.notes_admin || ''"></td>
                                    <td class="px-3 py-2 text-center">
                                        <button type="button"
                                                @click="toggleOverride(modelCode, varName, varData)"
                                                class="px-2 py-1 rounded text-[10px] border border-gray-700 text-gray-400 hover:bg-gray-800 hover:text-gray-200 transition">
                                            <i class="fa-solid fa-toggle-on"></i> Toggle
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <template x-if="Object.keys(models).length === 0 && !loading">
            <x-admin.empty-state icon="fa-solid fa-table-cells" message="Aucun modèle trouvé. Vérifiez que le sidecar est en ligne." />
        </template>
    </div>
</div>

{{-- Override form (hidden, submitted via JS) --}}
<form id="override-form" method="POST" action="{{ route('admin.meteo.settings.variable-override') }}" class="hidden">
    @csrf
    <input type="hidden" name="model_code" id="override-model-code">
    <input type="hidden" name="variable" id="override-variable">
    <input type="hidden" name="enabled" id="override-enabled">
    <input type="hidden" name="notes_admin" id="override-notes">
</form>

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

@push('scripts')
<script>
function variablesPanel() {
    return {
        loading: true,
        error: null,
        models: {},

        async loadData() {
            try {
                const r = await fetch(@js($sidecarVariablesEndpoint), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: AbortSignal.timeout(10000),
                });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const data = await r.json();
                if (data.error) throw new Error(data.message || 'Erreur sidecar');
                const raw = data.models || data;
                for (const [code, model] of Object.entries(raw)) {
                    model._open = false;
                }
                this.models = raw;
            } catch (e) {
                this.error = 'Sidecar injoignable : ' + e.message;
                this.loadFallback();
            } finally {
                this.loading = false;
            }
        },

        loadFallback() {
            const overrides = @js($overrides->groupBy('model_code')->map(fn ($group) => $group->keyBy('variable')));
            for (const [code, vars] of Object.entries(overrides)) {
                const variables = {};
                for (const [varName, override] of Object.entries(vars)) {
                    variables[varName] = {
                        override_enabled: override.enabled,
                        notes_admin: override.notes_admin,
                    };
                }
                this.models[code] = { active_in_db: true, weight_factor: 1.0, variables, _open: false };
            }
        },

        toggleOverride(modelCode, varName, varData) {
            const currentOverride = varData.override_enabled;
            let newEnabled;
            if (currentOverride === null || currentOverride === undefined) {
                newEnabled = 0;
            } else if (currentOverride === false) {
                newEnabled = 1;
            } else {
                newEnabled = 1;
            }
            const notes = prompt('Notes admin (optionnel) :', varData.notes_admin || '');
            if (notes === null && currentOverride !== null && currentOverride !== undefined) return;

            document.getElementById('override-model-code').value = modelCode;
            document.getElementById('override-variable').value = varName;
            document.getElementById('override-enabled').value = newEnabled;
            document.getElementById('override-notes').value = notes || '';
            document.getElementById('override-form').submit();
        },
    };
}
</script>
@endpush
