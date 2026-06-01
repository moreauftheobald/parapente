{{-- Onglet Dépendances — graphe read-only depuis le sidecar /v1/consensus/dependency_graph --}}

<div x-data="dependenciesPanel()" x-init="loadGraph()">
    <div x-show="loading" class="text-center py-12 text-gray-500">
        <i class="fa-solid fa-spinner fa-spin text-2xl"></i>
        <p class="mt-2 text-sm">Chargement du graphe de dépendances…</p>
    </div>

    <div x-show="error && !loading" x-cloak>
        <x-admin.alert type="warning">
            <span x-text="error"></span>
        </x-admin.alert>
        <x-admin.empty-state icon="fa-solid fa-diagram-project" message="Le graphe de dépendances sera disponible quand le sidecar exposera l'endpoint /v1/consensus/dependency_graph." />
    </div>

    <div x-show="!loading && !error" x-cloak>
        {{-- Tableau des dépendances --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-6">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                    <tr>
                        <th class="text-left px-3 py-2 font-medium">Variable</th>
                        <th class="text-left px-3 py-2 font-medium">Type</th>
                        <th class="text-left px-3 py-2 font-medium">Sources Open-Meteo</th>
                        <th class="text-left px-3 py-2 font-medium">Dépend de</th>
                        <th class="text-left px-3 py-2 font-medium">Utilisée par</th>
                        <th class="text-left px-3 py-2 font-medium">Formule</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/70">
                    <template x-for="(varData, varName) in variables" :key="varName">
                        <tr class="hover:bg-gray-800/30 transition">
                            <td class="px-3 py-2">
                                <span class="font-mono text-xs text-white" x-text="varName"></span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="text-[10px] px-1.5 py-0.5 rounded border"
                                      :class="varData.type === 'consensus'
                                          ? 'bg-sky-500/15 text-sky-300 border-sky-500/30'
                                          : 'bg-violet-500/15 text-violet-300 border-violet-500/30'"
                                      x-text="varData.type === 'consensus' ? 'Consensus' : 'Dérivée'"></span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-400">
                                <template x-for="src in (varData.sources_om || [])" :key="src">
                                    <span class="inline-block font-mono bg-gray-800 px-1 py-0.5 rounded text-[10px] mr-1 mb-0.5" x-text="src"></span>
                                </template>
                                <span x-show="!varData.sources_om?.length" class="text-gray-600">—</span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-400">
                                <template x-for="dep in (varData.derived_from || [])" :key="dep">
                                    <span class="inline-block font-mono bg-violet-500/10 px-1 py-0.5 rounded text-[10px] text-violet-300 mr-1 mb-0.5" x-text="dep"></span>
                                </template>
                                <template x-for="ext in (varData.depends_on_external || [])" :key="ext">
                                    <span class="inline-block font-mono bg-amber-500/10 px-1 py-0.5 rounded text-[10px] text-amber-300 mr-1 mb-0.5" x-text="ext"></span>
                                </template>
                                <span x-show="!varData.derived_from?.length && !varData.depends_on_external?.length" class="text-gray-600">—</span>
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-400">
                                <template x-for="usr in (varData.used_by || [])" :key="usr">
                                    <span class="inline-block font-mono bg-emerald-500/10 px-1 py-0.5 rounded text-[10px] text-emerald-300 mr-1 mb-0.5" x-text="usr"></span>
                                </template>
                                <span x-show="!varData.used_by?.length" class="text-gray-600">—</span>
                            </td>
                            <td class="px-3 py-2 text-[10px] font-mono text-gray-500" x-text="varData.formula || '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500">
            <i class="fa-solid fa-circle-info text-gray-600"></i>
            Ce graphe est en lecture seule — les dépendances sont des formules physiques codées en dur côté sidecar.
            Quand un modèle met à jour une variable source (ex: <code class="font-mono">temperature_2m</code>), toutes les dérivées en cascade sont recalculées.
        </p>
    </div>
</div>

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

@push('scripts')
<script>
function dependenciesPanel() {
    const baseUrl = @js(rtrim(config('services.consensus_grid.base_url', 'http://consensus-grid:8082'), '/') . '/v1');
    return {
        loading: true,
        error: null,
        variables: {},

        async loadGraph() {
            try {
                const r = await fetch(baseUrl + '/consensus/dependency_graph', {
                    headers: { 'Accept': 'application/json' },
                    signal: AbortSignal.timeout(8000),
                });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const data = await r.json();
                this.variables = data.variables || data;
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
