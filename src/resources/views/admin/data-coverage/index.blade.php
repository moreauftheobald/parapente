@extends('layouts.admin')
@section('title', 'Couverture des données')

@php
    /**
     * Couleur de cellule de couverture.
     *  - !in_horizon : gris foncé (hors horizon du modèle, attendu)
     *  - null pct    : non calculable (dénominateur 0)
     *  - 0           : aucune donnée
     *  - <50%        : rouge
     *  - 50-95       : orange
     *  - >=95        : vert
     */
    $coverageCellClass = function ($cell): string {
        // Compat ancien format (cellule = float|null)
        if (is_array($cell)) {
            if (! ($cell['in_horizon'] ?? true)) {
                return 'bg-gray-950 text-gray-700 border-gray-800';
            }
            $pct = $cell['pct'] ?? null;
        } else {
            $pct = $cell;
        }
        if ($pct === null) return 'bg-gray-800 text-gray-600 border-gray-700';
        if ($pct < 0.1)    return 'bg-gray-900 text-gray-600 border-gray-800';
        if ($pct < 50)     return 'bg-red-500/20 text-red-200 border-red-500/40';
        if ($pct < 95)     return 'bg-amber-500/15 text-amber-200 border-amber-500/40';
        return 'bg-emerald-500/15 text-emerald-200 border-emerald-500/40';
    };

    $fmtCell = function ($cell): string {
        if (is_array($cell)) {
            if (! ($cell['in_horizon'] ?? true)) return '—';
            $pct = $cell['pct'] ?? null;
        } else {
            $pct = $cell;
        }
        if ($pct === null) return '—';
        if ($pct < 0.1)    return '0';
        return rtrim(rtrim(number_format($pct, 1, ',', ''), '0'), ',') . '%';
    };

    $cellTooltip = function ($cell): string {
        if (is_array($cell)) {
            if (! ($cell['in_horizon'] ?? true)) {
                $h = $cell['expected_h'] ?? 0;
                return $h === 0 ? 'Hors horizon du modèle' : 'Hors horizon';
            }
            $pct = $cell['pct'] ?? null;
            $h   = $cell['expected_h'] ?? null;
            $base = $pct === null ? 'N/A' : $pct . ' %';
            return $h !== null ? "$base (attendu : {$h} h/jour)" : $base;
        }
        return $cell === null ? 'N/A' : $cell . ' %';
    };

    // Compatibilité ascendante : ancien helper utilisé par la section 4
    // (relevés balises) qui n'a pas la notion d'horizon.
    $fmtPct = $fmtCell;

    $fmtDateTime = function (?\Carbon\Carbon $dt): string {
        if ($dt === null) return '—';
        return $dt->format('d/m H:i');
    };

    $fmtDay = function (\Carbon\CarbonImmutable $day, \Carbon\CarbonImmutable $today): string {
        $delta = $today->diffInDays($day, false);
        $delta = (int) $delta;
        if ($delta === 0)  return 'Aujourd’hui';
        if ($delta === 1)  return 'Demain';
        if ($delta === -1) return 'Hier';
        if ($delta > 0)    return 'J+' . $delta;
        return 'J' . $delta; // déjà signé négatif
    };

    $driftBadge = function (?float $pct): string {
        if ($pct === null) return 'bg-gray-800 text-gray-500 border-gray-700';
        $abs = abs($pct);
        if ($abs < 15) return 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40';
        if ($abs < 50) return 'bg-amber-500/15 text-amber-300 border-amber-500/40';
        return 'bg-red-500/20 text-red-300 border-red-500/40';
    };
@endphp

@section('content')
<div class="max-w-[1400px]">
    <h1 class="text-2xl font-semibold text-white mb-1">Couverture des données météo</h1>
    <p class="text-sm text-gray-500 mb-6">
        Ratio <span class="font-mono">heures reçues / heures attendues</span> sur la période de rétention de chaque source.
        Une cellule à 100 % signifie qu’on a une ligne par heure pour chaque unité active (site ou balise) ce jour-là.
        <span class="text-gray-600">Cache 5 min.</span>
    </p>

    {{-- ─────────────────────────────────────────────────────────────
         Section 1 — Fraîcheur des modèles météo
    ───────────────────────────────────────────────────────────────── --}}
    <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3 mt-2">
        <i class="fa-solid fa-cloud text-violet-400"></i> Modèles météo &mdash; fraîcheur des fetches
    </h2>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-10"
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

            {{-- Résultat du test (parsing complet) --}}
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

            {{-- Inspection brute --}}
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

    <p class="text-xs text-gray-500 -mt-7 mb-10">
        <i class="fa-solid fa-circle-info text-gray-600"></i>
        La <em>cadence réelle</em> est l’intervalle entre les 2 derniers fetches enregistrés (tous scopes confondus).
        Un décalage &gt; 50 % suggère que le job ne tourne pas à la cadence prévue.
        L’heure du <em>run provider</em> n’est pas encore extraite des réponses Open-Meteo (chantier à venir).
    </p>

    {{-- ─────────────────────────────────────────────────────────────
         Section 2 — Couverture prévisions sites
    ───────────────────────────────────────────────────────────────── --}}
    <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
        <i class="fa-solid fa-mountain-sun text-sky-400"></i>
        Prévisions sites &mdash; J → J+4
        <span class="text-gray-600 normal-case">({{ $siteForecasts['sites_active'] }} sites actifs)</span>
    </h2>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-10">
        <table class="w-full text-sm">
            <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                <tr>
                    <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Modèle</th>
                    @foreach ($siteForecasts['days'] as $day)
                        <th class="text-center px-3 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                            <div class="text-white">{{ $fmtDay($day, $today) }}</div>
                            <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D MMM') }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/70">
                @forelse ($siteForecasts['rows'] as $row)
                    <tr>
                        <td class="px-3 py-2 sticky left-0 bg-gray-900 z-10">
                            <div class="text-white">{{ $row['model']->name }}</div>
                            <div class="text-xs text-gray-500 font-mono">
                                {{ $row['model']->code }}
                                <span class="ml-1 text-gray-600">· {{ $row['model']->max_horizon_h }}h</span>
                            </div>
                        </td>
                        @foreach ($row['cells'] as $cell)
                            <td class="px-2 py-2 text-center">
                                <span class="inline-block min-w-[64px] px-2 py-1 rounded border text-xs font-mono {{ $coverageCellClass($cell) }}"
                                      title="{{ $cellTooltip($cell) }}">
                                    {{ $fmtCell($cell) }}
                                </span>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($siteForecasts['days']) + 1 }}" class="px-3 py-6 text-center text-gray-500">Aucun modèle actif.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ─────────────────────────────────────────────────────────────
         Section 3 — Couverture prévisions balises
    ───────────────────────────────────────────────────────────────── --}}
    <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
        <i class="fa-solid fa-box-archive text-violet-400"></i>
        Prévisions balises &mdash; J-7 → J+5
        <span class="text-gray-600 normal-case">({{ $baliseForecasts['balises_active'] }} balises actives, horizon archivé limité à J+2)</span>
    </h2>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-10">
        <table class="w-full text-sm">
            <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                <tr>
                    <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Modèle</th>
                    @foreach ($baliseForecasts['days'] as $day)
                        <th class="text-center px-2 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                            <div class="text-white text-[11px]">{{ $fmtDay($day, $today) }}</div>
                            <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D/MM') }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/70">
                @forelse ($baliseForecasts['rows'] as $row)
                    <tr @class(['opacity-60' => ! $row['archived']])>
                        <td class="px-3 py-2 sticky left-0 bg-gray-900 z-10">
                            <div class="text-white">
                                {{ $row['model']->name }}
                                @unless ($row['archived'])
                                    <span class="ml-1 text-[10px] uppercase tracking-wider text-gray-500"
                                          title="Modèle d'horizon < 24h — exclu de l'archive balises par FetchBaliseForecastsJob">non archivé</span>
                                @endunless
                            </div>
                            <div class="text-xs text-gray-500 font-mono">
                                {{ $row['model']->code }}
                                <span class="ml-1 text-gray-600">· {{ $row['model']->max_horizon_h }}h</span>
                            </div>
                        </td>
                        @foreach ($row['cells'] as $cell)
                            <td class="px-1 py-2 text-center">
                                <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($cell) }}"
                                      title="{{ $cellTooltip($cell) }}">
                                    {{ $fmtCell($cell) }}
                                </span>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($baliseForecasts['days']) + 1 }}" class="px-3 py-6 text-center text-gray-500">Aucun modèle actif ou aucune balise active.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ─────────────────────────────────────────────────────────────
         Section 4 — Couverture relevés balises
    ───────────────────────────────────────────────────────────────── --}}
    <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
        <i class="fa-solid fa-tower-broadcast text-emerald-400"></i>
        Relevés balises &mdash; J-7 → J
        <span class="text-gray-600 normal-case">(agrégat horaire de <code>balise_readings_hourly</code>)</span>
    </h2>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-10">
        <table class="w-full text-sm">
            <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                <tr>
                    <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Réseau / Balise</th>
                    @foreach ($baliseReadings['days'] as $day)
                        <th class="text-center px-2 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                            <div class="text-white text-[11px]">{{ $fmtDay($day, $today) }}</div>
                            <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D/MM') }}</div>
                        </th>
                    @endforeach
                    <th class="text-right px-3 py-2 font-medium">Dernière réception</th>
                </tr>
            </thead>
            @forelse ($baliseReadings['groups'] as $group)
                <tbody class="divide-y divide-gray-800/70 border-t border-gray-800/70"
                       x-data="{ open: false }">
                    {{-- Ligne de synthèse réseau, cliquable pour déplier --}}
                    <tr class="bg-gray-800/40 hover:bg-gray-800/70 transition cursor-pointer select-none"
                        @click="open = !open">
                        <td class="px-3 py-2 sticky left-0 bg-gray-800/40 z-10">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-chevron-right w-3 text-gray-400 transition-transform"
                                   :class="open && 'rotate-90'"></i>
                                <span class="text-white font-medium">{{ $group['label'] }}</span>
                                <span class="text-xs text-gray-500">({{ $group['balises_count'] }} balise{{ $group['balises_count'] > 1 ? 's' : '' }})</span>
                            </div>
                        </td>
                        @foreach ($group['aggregate_cells'] as $pct)
                            <td class="px-1 py-2 text-center">
                                <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($pct) }}"
                                      title="{{ $pct === null ? 'N/A' : 'Agrégat réseau : '.$pct.'%' }}">
                                    {{ $fmtPct($pct) }}
                                </span>
                            </td>
                        @endforeach
                        <td class="px-3 py-2 text-right text-xs text-gray-500" x-show="!open">
                            <span class="text-gray-500">détail&hellip;</span>
                        </td>
                        <td class="px-3 py-2 text-right text-xs text-gray-500" x-show="open" x-cloak>
                            <span class="text-gray-500">réduire</span>
                        </td>
                    </tr>

                    {{-- Lignes détail par balise (repliables) --}}
                    @foreach ($group['balises'] as $row)
                        <tr x-show="open" x-cloak class="hover:bg-gray-800/30 transition">
                            <td class="px-3 py-1.5 sticky left-0 bg-gray-900 z-10 pl-8">
                                <div class="text-white text-[13px]">{{ $row['balise']->name }}</div>
                                <div class="text-[10px] text-gray-500 font-mono">#{{ $row['balise']->id }}</div>
                            </td>
                            @foreach ($row['cells'] as $pct)
                                <td class="px-1 py-1.5 text-center">
                                    <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($pct) }}"
                                          title="{{ $pct === null ? 'N/A' : $pct.'%' }}">
                                        {{ $fmtPct($pct) }}
                                    </span>
                                </td>
                            @endforeach
                            <td class="px-3 py-1.5 text-right font-mono text-[11px] text-gray-300">{{ $fmtDateTime($row['last_reading_at']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody>
                    <tr><td colspan="{{ count($baliseReadings['days']) + 2 }}" class="px-3 py-6 text-center text-gray-500">Aucune balise active.</td></tr>
                </tbody>
            @endforelse
        </table>
    </div>

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

    {{-- Légende --}}
    <div class="text-xs text-gray-500 flex flex-wrap items-center gap-3">
        <span class="text-gray-400 font-medium">Légende :</span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-4 h-4 rounded border bg-emerald-500/15 border-emerald-500/40"></span>
            ≥ 95 %
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-4 h-4 rounded border bg-amber-500/15 border-amber-500/40"></span>
            50–95 %
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-4 h-4 rounded border bg-red-500/20 border-red-500/40"></span>
            &lt; 50 %
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-4 h-4 rounded border bg-gray-900 border-gray-800"></span>
            aucune donnée
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-4 h-4 rounded border bg-gray-950 border-gray-800"></span>
            hors horizon
        </span>
        <span class="inline-flex items-center gap-2">
            <span class="inline-block w-4 h-4 rounded border bg-gray-800 border-gray-700"></span>
            non calculable
        </span>
    </div>
</div>
@endsection

@section('help')
<div class="text-sm text-gray-300 space-y-3">
    <h3 class="font-semibold text-white">Comment lire ces tableaux ?</h3>
    <p>
        Chaque cellule représente le taux de remplissage <strong>du jour</strong> :
        <code>heures distinctes reçues</code> divisé par <code>24 × nombre d’unités actives</code>.
    </p>
    <p>
        Une cellule <span class="text-amber-300">orange</span> ou <span class="text-red-300">rouge</span>
        en milieu de période est anormale (modèle qui ne ramène rien, balise en panne, fetch coupé).
        Les jours futurs apparaissent <span class="text-gray-500">vides</span> tant que le job ne les a pas encore couverts.
    </p>
    <h4 class="font-semibold text-white mt-3">Périmètre actif</h4>
    <p class="text-gray-400">
        Seuls les <strong>sites et balises actuellement actifs</strong> sont comptés
        au dénominateur. Une balise désactivée hier ne pénalise pas l’historique.
    </p>
    <h4 class="font-semibold text-white mt-3">Rétentions</h4>
    <ul class="list-disc list-inside text-gray-400 space-y-0.5">
        <li>Prévisions sites : <code>forecasts</code> &mdash; J-1 → J+4 (purgé à J-1)</li>
        <li>Prévisions balises : <code>forecast_archive_balises</code> &mdash; 30 j</li>
        <li>Relevés balises : <code>balise_readings_hourly</code> &mdash; 7 j</li>
        <li>Journal des fetches : <code>weather_fetch_log</code> &mdash; 30 j</li>
    </ul>
</div>
@endsection
