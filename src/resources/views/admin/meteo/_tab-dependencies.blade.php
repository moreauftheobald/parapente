{{-- Onglet Dépendances — graphe read-only depuis le sidecar /v1/consensus/dependency_graph --}}

<div x-data="dependenciesPanel()" x-init="loadGraph()" data-proxy-url="{{ route('admin.meteo.sidecar.dependency-graph', [], false) }}">
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

        {{-- Mini DAG Mermaid --}}
        <x-admin.section title="Graphe de dépendances" icon="fa-solid fa-share-nodes" color="violet" class="mb-6">
            <div id="mermaid-dag" class="overflow-x-auto bg-gray-950 rounded-lg p-4 border border-gray-800 min-h-[120px]">
                <p class="text-xs text-gray-600 animate-pulse">Rendu du graphe…</p>
            </div>
        </x-admin.section>

        {{-- Tableau des dépendances --}}
        <x-admin.section title="Détail par variable" icon="fa-solid fa-table-cells" color="gray" class="mb-6">
            <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
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
        </x-admin.section>

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
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>
@verbatim
<script>
mermaid.initialize({
    startOnLoad: false,
    theme: 'dark',
    themeVariables: {
        primaryColor:       '#0ea5e9',
        primaryTextColor:   '#e2e8f0',
        primaryBorderColor: '#38bdf8',
        lineColor:          '#64748b',
        secondaryColor:     '#7c3aed',
        tertiaryColor:      '#1e293b',
        fontFamily:         'ui-monospace, monospace',
        fontSize:           '11px',
    },
    flowchart: { curve: 'basis', padding: 12 },
});

function dependenciesPanel() {
    const proxyUrl = document.querySelector('[data-proxy-url]')?.dataset.proxyUrl;
    return {
        loading: true,
        error: null,
        variables: {},

        async loadGraph() {
            try {
                const r = await fetch(proxyUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: AbortSignal.timeout(10000),
                });
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const data = await r.json();
                if (data.error) throw new Error(data.message || 'Erreur sidecar');
                this.variables = data.variables || data;
                this.$nextTick(() => this.renderDag());
            } catch (e) {
                this.error = 'Sidecar injoignable : ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        renderDag() {
            const vars = this.variables;
            const names = Object.keys(vars);
            if (!names.length) return;

            const sanitize = (n) => n.replace(/[^a-zA-Z0-9_]/g, '_');
            let lines = ['graph LR'];

            for (const name of names) {
                const v = vars[name];
                const id = sanitize(name);
                // Mermaid rhombus shape for external deps, rounded for derived, square for consensus
                const shape = v.type === 'derived' ? `${id}([${name}])` : `${id}[${name}]`;
                const style = v.type === 'derived'
                    ? `style ${id} fill:#7c3aed22,stroke:#7c3aed,color:#c4b5fd`
                    : `style ${id} fill:#0ea5e922,stroke:#0ea5e9,color:#7dd3fc`;
                lines.push('    ' + shape);
                lines.push('    ' + style);
            }

            for (const name of names) {
                const v = vars[name];
                const id = sanitize(name);
                for (const dep of (v.derived_from || [])) {
                    if (names.includes(dep)) {
                        lines.push('    ' + sanitize(dep) + ' --> ' + id);
                    }
                }
                for (const ext of (v.depends_on_external || [])) {
                    const extId = sanitize('ext_' + ext);
                    // Mermaid hexagon shape for external dependencies
                    lines.push('    ' + extId + '{' + '{' + ext + '}' + '}');
                    lines.push('    style ' + extId + ' fill:#f59e0b22,stroke:#f59e0b,color:#fcd34d');
                    lines.push('    ' + extId + ' -.-> ' + id);
                }
            }

            const definition = lines.join('\n');
            const el = document.getElementById('mermaid-dag');
            if (!el) return;

            el.innerHTML = '';
            mermaid.render('mermaid-dag-svg', definition).then(function(result) {
                el.innerHTML = result.svg;
            }).catch(function() {
                el.innerHTML = '<p class="text-xs text-gray-500">Impossible de rendre le graphe Mermaid.</p>';
            });
        },
    };
}
</script>
@endverbatim
@endpush

