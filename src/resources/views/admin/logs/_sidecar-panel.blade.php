{{-- Onglet Log / Monitoring — historique des runs sidecar V2 --}}

<div x-data="logsPanel()" x-init="load()">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xs uppercase tracking-wider text-gray-400">
            <i class="fa-solid fa-scroll text-sky-400"></i>
            Historique des runs consensus
        </h2>
        <button type="button" @click="load()"
                :disabled="loading"
                class="px-3 py-1.5 rounded text-xs border border-sky-500/40 text-sky-300 hover:bg-sky-500/15 disabled:opacity-40 transition">
            <i class="fa-solid" :class="loading ? 'fa-spinner fa-spin' : 'fa-arrows-rotate'"></i>
            Rafraîchir
        </button>
    </div>

    <div x-show="loading && !stats" class="text-center py-12 text-gray-500">
        <i class="fa-solid fa-spinner fa-spin text-2xl"></i>
        <p class="mt-2 text-sm">Chargement des logs sidecar…</p>
    </div>

    <div x-show="error && !stats" x-cloak>
        <x-admin.alert type="warning">
            <span x-text="error"></span>
        </x-admin.alert>
        <x-admin.empty-state icon="fa-solid fa-scroll" message="Le sidecar V2 ne répond pas ou n'expose pas encore les endpoints /v1/runs/*." />
    </div>

    <div x-show="stats || runs.length" x-cloak class="space-y-5">

        {{-- Statistiques agrégées --}}
        <div x-show="stats" class="bg-gray-900 border border-gray-800 rounded-xl p-4">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 mb-3">
                <i class="fa-solid fa-chart-bar text-gray-600"></i>
                Statistiques (<span x-text="stats?.total_runs || 0"></span> runs)
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3">
                <div class="bg-gray-950 rounded-lg px-3 py-2 border border-gray-800">
                    <span class="text-[10px] text-gray-500 block">Taux de succès</span>
                    <span class="text-sm font-mono font-semibold"
                          :class="(stats?.success_rate_pct ?? 0) >= 90 ? 'text-emerald-300' : ((stats?.success_rate_pct ?? 0) >= 70 ? 'text-amber-300' : 'text-red-300')"
                          x-text="(stats?.success_rate_pct ?? 0).toFixed(1) + ' %'"></span>
                </div>
                <div class="bg-gray-950 rounded-lg px-3 py-2 border border-gray-800">
                    <span class="text-[10px] text-gray-500 block">Completed</span>
                    <span class="text-sm font-mono text-emerald-300" x-text="stats?.by_status?.completed ?? 0"></span>
                </div>
                <div class="bg-gray-950 rounded-lg px-3 py-2 border border-gray-800">
                    <span class="text-[10px] text-gray-500 block">Partial</span>
                    <span class="text-sm font-mono text-amber-300" x-text="stats?.by_status?.partial ?? 0"></span>
                </div>
                <div class="bg-gray-950 rounded-lg px-3 py-2 border border-gray-800">
                    <span class="text-[10px] text-gray-500 block">Failed</span>
                    <span class="text-sm font-mono text-red-300" x-text="stats?.by_status?.failed ?? 0"></span>
                </div>
                <div class="bg-gray-950 rounded-lg px-3 py-2 border border-gray-800">
                    <span class="text-[10px] text-gray-500 block">Durée médiane</span>
                    <span class="text-sm font-mono text-white" x-text="formatDuration(stats?.duration_s?.median)"></span>
                </div>
                <div class="bg-gray-950 rounded-lg px-3 py-2 border border-gray-800">
                    <span class="text-[10px] text-gray-500 block">Durée min / max</span>
                    <span class="text-sm font-mono text-gray-400" x-text="formatDuration(stats?.duration_s?.min) + ' / ' + formatDuration(stats?.duration_s?.max)"></span>
                </div>
            </div>

            {{-- Modèles vus --}}
            <div x-show="stats?.models_seen?.length" class="mt-3">
                <span class="text-[10px] text-gray-500">Modèles vus récemment</span>
                <div class="flex flex-wrap gap-1 mt-1">
                    <template x-for="m in (stats?.models_seen || [])" :key="m">
                        <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-sky-500/10 text-sky-300 border border-sky-500/20" x-text="m"></span>
                    </template>
                </div>
            </div>
        </div>

        {{-- Historique des runs --}}
        <div x-show="runs.length" class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 px-4 pt-3 pb-2">
                <i class="fa-solid fa-clock-rotate-left text-gray-600"></i>
                Derniers runs
            </h3>
            <table class="w-full text-[11px]">
                <thead class="text-gray-500 border-b border-gray-800">
                    <tr>
                        <th class="text-left px-3 py-1.5 font-medium">Statut</th>
                        <th class="text-left px-3 py-1.5 font-medium">Début</th>
                        <th class="text-left px-3 py-1.5 font-medium">Fin</th>
                        <th class="text-right px-3 py-1.5 font-medium">Durée</th>
                        <th class="text-left px-3 py-1.5 font-medium">Run init</th>
                        <th class="text-center px-3 py-1.5 font-medium">Bandes</th>
                        <th class="text-right px-3 py-1.5 font-medium">Variables</th>
                        <th class="text-right px-3 py-1.5 font-medium">Fichiers</th>
                        <th class="text-right px-3 py-1.5 font-medium">Taille</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/50">
                    <template x-for="(run, idx) in runs" :key="idx">
                        <tr class="hover:bg-gray-800/30 transition cursor-pointer"
                            @click="expandedRun === idx ? expandedRun = null : expandedRun = idx">
                            <td class="px-3 py-1.5">
                                <span class="px-1.5 py-0.5 rounded border text-[10px] font-medium"
                                      :class="statusClass(run.status)"
                                      x-text="run.status"></span>
                            </td>
                            <td class="px-3 py-1.5 font-mono text-gray-300" x-text="fmtDate(run.started_at)"></td>
                            <td class="px-3 py-1.5 font-mono text-gray-300" x-text="fmtDate(run.finished_at)"></td>
                            <td class="px-3 py-1.5 font-mono text-white text-right" x-text="formatDuration(run.duration_s)"></td>
                            <td class="px-3 py-1.5 font-mono text-gray-400" x-text="fmtDate(run.run_init_iso)"></td>
                            <td class="px-3 py-1.5 text-center">
                                <template x-for="b in (run.bands || [])" :key="b">
                                    <span class="inline-block text-[9px] font-mono px-1 py-0.5 rounded bg-gray-800 text-gray-400 mr-0.5" x-text="b"></span>
                                </template>
                            </td>
                            <td class="px-3 py-1.5 font-mono text-gray-300 text-right" x-text="run.n_variables ?? '—'"></td>
                            <td class="px-3 py-1.5 font-mono text-gray-300 text-right" x-text="run.files_written ?? '—'"></td>
                            <td class="px-3 py-1.5 font-mono text-gray-300 text-right" x-text="run.output_size_mb ? run.output_size_mb.toFixed(1) + ' Mo' : '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- Détail par variable du run sélectionné --}}
        <div x-show="expandedRun !== null && runs[expandedRun]?.per_variable_stats" x-cloak
             class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
            <h3 class="text-xs uppercase tracking-wider text-gray-500 px-4 pt-3 pb-2">
                <i class="fa-solid fa-magnifying-glass-chart text-gray-600"></i>
                Détail du run #<span x-text="(expandedRun ?? 0) + 1"></span>
            </h3>
            <table class="w-full text-[11px]">
                <thead class="text-gray-500 border-b border-gray-800">
                    <tr>
                        <th class="text-left px-3 py-1.5 font-medium">Variable</th>
                        <th class="text-right px-3 py-1.5 font-medium">Modèles moy.</th>
                        <th class="text-right px-3 py-1.5 font-medium">Données valides</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/50">
                    <template x-for="(vs, varName) in (expandedRun !== null ? runs[expandedRun]?.per_variable_stats || {} : {})" :key="varName">
                        <tr class="hover:bg-gray-800/30 transition">
                            <td class="px-3 py-1.5 font-mono text-gray-300" x-text="varName"></td>
                            <td class="px-3 py-1.5 font-mono text-white text-right" x-text="vs.avg_models ?? '—'"></td>
                            <td class="px-3 py-1.5 font-mono text-right"
                                :class="(vs.valid_pct ?? 0) >= 95 ? 'text-emerald-300' : ((vs.valid_pct ?? 0) >= 80 ? 'text-amber-300' : 'text-red-300')"
                                x-text="vs.valid_pct != null ? vs.valid_pct.toFixed(1) + ' %' : '—'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-500">
            <i class="fa-solid fa-circle-info text-gray-600"></i>
            Données en temps réel depuis le sidecar V2. Cliquez sur un run pour afficher le détail par variable.
        </p>
    </div>
</div>

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

@push('scripts')
@verbatim
<script>
function logsPanel() {
    return {
        loading: false,
        error: null,
        stats: null,
        runs: [],
        expandedRun: null,

        async load() {
            this.loading = true;
            this.error = null;
            try {
                const [recentR, statsR] = await Promise.all([
                    fetch(ROUTES_LOGS.recent, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: AbortSignal.timeout(12000),
                    }),
                    fetch(ROUTES_LOGS.stats, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: AbortSignal.timeout(12000),
                    }),
                ]);

                if (!recentR.ok && !statsR.ok) throw new Error(`HTTP ${recentR.status} / ${statsR.status}`);

                if (recentR.ok) {
                    const recentData = await recentR.json();
                    if (recentData.error) throw new Error(recentData.message || 'Erreur sidecar');
                    this.runs = recentData.runs || [];
                }
                if (statsR.ok) {
                    const statsData = await statsR.json();
                    if (!statsData.error) this.stats = statsData;
                }
            } catch (e) {
                this.error = 'Sidecar injoignable : ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        statusClass(status) {
            if (status === 'completed') return 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30';
            if (status === 'partial')   return 'bg-amber-500/15 text-amber-300 border-amber-500/30';
            if (status === 'running')   return 'bg-sky-500/15 text-sky-300 border-sky-500/30';
            return 'bg-red-500/15 text-red-300 border-red-500/30';
        },

        fmtDate(iso) {
            if (!iso) return '—';
            const d = new Date(iso);
            if (isNaN(d)) return iso;
            return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' })
                + ' ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        },

        formatDuration(s) {
            if (s == null) return '—';
            s = Math.round(s);
            if (s < 60) return s + ' s';
            const m = Math.floor(s / 60);
            const rest = s % 60;
            return m + ' min ' + (rest > 0 ? rest + ' s' : '');
        },
    };
}
</script>
@endverbatim
<script>
    const ROUTES_LOGS = {
        recent: @js($runsRecentEndpoint),
        stats:  @js($runsStatsEndpoint),
    };
</script>
@endpush
