@php require resource_path('views/admin/_coverage-helpers.php'); @endphp

{{-- Fraîcheur des modèles météo ─────────────────────────────── --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-6"
     x-data="modelFreshness()">
    <table class="w-full text-sm">
        <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
            <tr>
                <th class="text-left px-3 py-2 font-medium">Modèle</th>
                <th class="text-left px-3 py-2 font-medium">État</th>
                <th class="text-right px-3 py-2 font-medium" title="Cadence configurée">Cadence prévue</th>
                <th class="text-right px-3 py-2 font-medium" title="Intervalle réel entre les 2 derniers fetches">Cadence réelle</th>
                <th class="text-right px-3 py-2 font-medium">Décalage</th>
                <th class="text-right px-3 py-2 font-medium">Dernier fetch site</th>
                <th class="text-right px-3 py-2 font-medium">Lignes</th>
                <th class="text-right px-3 py-2 font-medium">Dernier fetch balise</th>
                <th class="text-right px-3 py-2 font-medium">Lignes</th>
                <th class="text-right px-3 py-2 font-medium">Run provider</th>
                <th class="text-center px-3 py-2 font-medium">Test</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-800/70">
            @forelse ($modelFreshness as $row)
                @php $m = $row['model']; @endphp
                <tr class="hover:bg-gray-800/30 transition" :class="testingId === {{ $m->id }} && 'bg-sky-500/5'">
                    <td class="px-3 py-2">
                        <div class="text-white">{{ $m->name }}</div>
                        <div class="text-xs text-gray-500 font-mono">{{ $m->code }}</div>
                    </td>
                    <td class="px-3 py-2">
                        @if ($m->active)
                            <span class="inline-block px-2 py-0.5 rounded text-xs bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">actif</span>
                        @else
                            <span class="inline-block px-2 py-0.5 rounded text-xs bg-gray-800 text-gray-500 border border-gray-700">inactif</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right font-mono text-gray-300">{{ $row['expected_interval_min'] }} min</td>
                    <td class="px-3 py-2 text-right font-mono text-gray-300">
                        {{ $row['observed_interval_min'] !== null ? $row['observed_interval_min'].' min' : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right">
                        <span class="inline-block px-2 py-0.5 rounded text-xs border font-mono {{ $driftBadge($row['drift_pct']) }}">
                            {{ $row['drift_pct'] !== null ? ($row['drift_pct'] >= 0 ? '+' : '').$row['drift_pct'].' %' : '—' }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-right font-mono text-gray-300">{{ $fmtDateTime($row['last_fetch_site']) }}</td>
                    <td class="px-3 py-2 text-right font-mono text-gray-300">
                        @if ($row['last_rows_site'] === null)
                            —
                        @elseif ($row['last_rows_site'] === 0)
                            <span class="text-red-300">0</span>
                        @else
                            {{ number_format($row['last_rows_site'], 0, ',', ' ') }}
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right font-mono text-gray-300">{{ $fmtDateTime($row['last_fetch_balise']) }}</td>
                    <td class="px-3 py-2 text-right font-mono text-gray-300">
                        @if ($row['last_rows_balise'] === null)
                            —
                        @elseif ($row['last_rows_balise'] === 0)
                            <span class="text-red-300">0</span>
                        @else
                            {{ number_format($row['last_rows_balise'], 0, ',', ' ') }}
                        @endif
                    </td>
                    <td class="px-3 py-2 text-right font-mono text-gray-300">{{ $fmtDateTime($row['last_provider_run']) }}</td>
                    <td class="px-2 py-2 text-center whitespace-nowrap">
                        <button type="button"
                                @click="testModel({{ $m->id }}, @js($m->code))"
                                :disabled="testingId !== null"
                                class="px-2 py-1 rounded text-xs border border-sky-500/40 text-sky-300 hover:bg-sky-500/15 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                title="Lance un fetch synchrone sur le 1er site actif">
                            <i class="fa-solid fa-bolt"></i>
                            <span x-text="testingId === {{ $m->id }} ? '…' : 'Tester'"></span>
                        </button>
                        <button type="button"
                                @click="inspectModel({{ $m->id }}, @js($m->code))"
                                :disabled="testingId !== null"
                                class="ml-1 px-2 py-1 rounded text-xs border border-violet-500/40 text-violet-300 hover:bg-violet-500/15 disabled:opacity-40 disabled:cursor-not-allowed transition"
                                title="Affiche la réponse brute d'Open-Meteo pour ce modèle">
                            <i class="fa-solid fa-microscope"></i>
                            <span x-text="testingId === {{ $m->id }} && inspectActive ? '…' : 'Brut'"></span>
                        </button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="px-3 py-6 text-center text-gray-500">Aucun modèle configuré.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Panneau résultat (test ou inspection brute) --}}
    <div x-show="result || inspectResult" x-cloak
         class="border-t border-gray-800 bg-gray-950/80 p-4">
        <div class="flex items-start justify-between mb-2">
            <div>
                <div class="text-xs uppercase tracking-wider text-gray-500"
                     x-text="inspectResult ? 'Inspection brute Open-Meteo' : 'Résultat du test'"></div>
                <div class="text-sm font-mono text-white" x-text="(inspectResult || result)?.code"></div>
            </div>
            <button @click="result = null; inspectResult = null"
                    class="text-gray-500 hover:text-gray-200 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <template x-if="result">
            <div>
                <div class="text-sm" :class="result?.success ? 'text-emerald-300' : 'text-red-300'"
                     x-text="result?.message"></div>
                <div x-show="result?.slots_count !== undefined" class="text-xs text-gray-500 mt-1"
                     x-text="`${result?.slots_count} créneaux · ${result?.duration_ms} ms · site test: ${result?.site}`"></div>
                <pre x-show="result?.sample"
                     class="mt-2 text-[11px] text-gray-400 bg-gray-900 border border-gray-800 rounded p-2 overflow-x-auto max-h-60"
                     x-text="JSON.stringify(result?.sample, null, 2)"></pre>
            </div>
        </template>

        <template x-if="inspectResult">
            <div>
                <div class="text-sm flex items-center gap-2">
                    <span class="font-mono px-2 py-0.5 rounded text-xs border"
                          :class="inspectResult.success
                            ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40'
                            : 'bg-red-500/15 text-red-300 border-red-500/40'"
                          x-text="`HTTP ${inspectResult.status}`"></span>
                    <span class="text-gray-400 text-xs" x-text="`site test: ${inspectResult.site}`"></span>
                </div>
                <div class="text-[11px] text-gray-500 mt-1 font-mono break-all">
                    URL : <span class="text-gray-400" x-text="inspectResult.url"></span>
                </div>
                <div class="mt-3">
                    <div class="text-xs uppercase tracking-wider text-gray-500 mb-2">Variables `hourly` (présence)</div>
                    <table class="text-[11px] font-mono w-full">
                        <thead class="text-gray-500">
                            <tr>
                                <th class="text-left py-1">Variable</th>
                                <th class="text-right py-1">Non-null</th>
                                <th class="text-right py-1">Total</th>
                                <th class="text-left py-1 pl-3">3 premières valeurs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(stats, name) in (inspectResult.hourly_stats || {})" :key="name">
                                <tr class="border-t border-gray-800/50">
                                    <td class="py-1 text-gray-300" x-text="name"></td>
                                    <td class="py-1 text-right"
                                        :class="stats.non_null === 0 ? 'text-red-300'
                                            : (stats.non_null < stats.total ? 'text-amber-300' : 'text-emerald-300')"
                                        x-text="stats.non_null"></td>
                                    <td class="py-1 text-right text-gray-500" x-text="stats.total"></td>
                                    <td class="py-1 pl-3 text-gray-400" x-text="JSON.stringify(stats.first3)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-gray-500 hover:text-gray-300">Payload complet (JSON)</summary>
                    <pre class="mt-2 text-[10px] text-gray-400 bg-gray-900 border border-gray-800 rounded p-2 overflow-x-auto max-h-96"
                         x-text="JSON.stringify(inspectResult.raw_body, null, 2)"></pre>
                </details>
            </div>
        </template>
    </div>
</div>

<p class="text-xs text-gray-500 mb-4">
    <i class="fa-solid fa-circle-info text-gray-600"></i>
    La <em>cadence réelle</em> est l'intervalle entre les 2 derniers fetches enregistrés (tous scopes confondus).
    Un décalage &gt; 50 % suggère que le job ne tourne pas à la cadence prévue.
</p>

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

@push('scripts')
<script>
    function modelFreshness() {
        return {
            testingId:     null,
            inspectActive: false,
            result:        null,
            inspectResult: null,
            async testModel(id, code) {
                this.testingId     = id;
                this.inspectActive = false;
                this.result        = { code, message: 'Fetch en cours…', success: true };
                this.inspectResult = null;
                try {
                    const r = await fetch(`/admin/models/${id}/test`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept':       'application/json',
                        },
                    });
                    const data = await r.json();
                    this.result = { ...data, code };
                } catch (e) {
                    this.result = { code, success: false, message: 'Erreur réseau : ' + e.message };
                } finally {
                    this.testingId = null;
                }
            },
            async inspectModel(id, code) {
                this.testingId     = id;
                this.inspectActive = true;
                this.result        = null;
                this.inspectResult = { code, status: 0, hourly_stats: {} };
                try {
                    const r = await fetch(`/admin/models/${id}/inspect`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept':       'application/json',
                        },
                    });
                    const data = await r.json();
                    this.inspectResult = { ...data, code };
                } catch (e) {
                    this.inspectResult = { code, success: false, status: 0, message: 'Erreur réseau : ' + e.message };
                } finally {
                    this.testingId     = null;
                    this.inspectActive = false;
                }
            },
        };
    }
</script>
@endpush
