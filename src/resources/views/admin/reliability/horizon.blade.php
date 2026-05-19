@extends('layouts.admin')
@section('title', 'Fiabilité · MAE par horizon')

@php
    $variableLabels = [
        'wind_speed_avg' => 'Vent moyen (km/h)',
        'wind_speed_max' => 'Rafales (km/h)',
        'wind_direction' => 'Direction (°)',
    ];
    $isDir = ($variable ?? null) === 'wind_direction';
    $unit  = $isDir ? '°' : 'km/h';

    $bucketLabels = [
        'nowcast'  => 'nowcast (0-6h)',
        'same_day' => 'same-day (6-24h)',
        'j_plus_1' => 'J+1 (24-48h)',
        'j_plus_2' => 'J+2 (48-72h)',
    ];

    // Détermine le meilleur algo (A/B/C) d'une ligne de MAE
    $bestKey = function (array $mae): ?string {
        $vals = array_filter([
            'a' => $mae['mae_a'] ?? null,
            'b' => $mae['mae_b'] ?? null,
            'c' => $mae['mae_c'] ?? null,
        ], fn ($v) => $v !== null);
        if ($vals === []) return null;
        return array_keys($vals, min($vals))[0];
    };

    $fmt = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
@endphp

@section('content')
<x-admin.page-title
    title="MAE par horizon — créneaux communs"
    subtitle="Comparaison apple-to-apple des 4 buckets sur les target_at présents dans tous les buckets simultanément. Évite le biais des fenêtres d'observation non-recouvrantes." />

@if ($panel->isEmpty())
    <x-admin.empty-state
        icon="fa-solid fa-flask-vial"
        message="Aucune balise n'est marquée dans le panel de test fiabilité.">
        <x-slot:actions>
            <a href="{{ route('admin.balises.index') }}"
               class="text-sky-400 hover:text-sky-300 text-sm">
                <i class="fa-solid fa-arrow-right"></i>
                Activer le drapeau « Panel test fiabilité » sur une fiche balise
            </a>
        </x-slot:actions>
    </x-admin.empty-state>
@else

{{-- Filtres --}}
<form method="GET" class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-5 flex flex-wrap items-end gap-4">
    <div class="flex flex-col gap-1">
        <label class="text-[11px] uppercase tracking-wider text-gray-400" for="balise">Balise</label>
        <select id="balise" name="balise"
                onchange="this.form.submit()"
                class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-gray-100 focus:outline-none focus:ring-1 focus:ring-sky-500">
            @foreach ($panel as $b)
                <option value="{{ $b->id }}" @selected($b->id === $balise->id)>{{ $b->name }} ({{ $b->source }})</option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col gap-1">
        <label class="text-[11px] uppercase tracking-wider text-gray-400" for="variable">Variable</label>
        <select id="variable" name="variable"
                onchange="this.form.submit()"
                class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-gray-100 focus:outline-none focus:ring-1 focus:ring-sky-500">
            @foreach ($variableLabels as $k => $label)
                <option value="{{ $k }}" @selected($k === $variable)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="ml-auto flex items-center gap-4">
        <a href="{{ route('admin.reliability.compare', ['balise' => $balise->id, 'variable' => $variable]) }}"
           class="text-xs text-gray-400 hover:text-sky-300 transition flex items-center gap-2">
            <i class="fa-solid fa-table"></i> Voir la comparaison heure par heure
        </a>
        <a href="{{ route('admin.reliability.models', ['balise' => $balise->id, 'variable' => $variable]) }}"
           class="text-xs text-gray-400 hover:text-sky-300 transition flex items-center gap-2">
            <i class="fa-solid fa-table-list"></i> Voir la fiabilité par modèle
        </a>
    </div>
</form>

{{-- ── Tableau "commun" — target_at présents dans les 4 buckets ── --}}
<section class="mb-8">
    <div class="flex items-baseline justify-between mb-3">
        <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider flex items-center gap-2">
            <i class="fa-solid fa-bullseye text-sky-400"></i>
            Comparaison stricte (créneaux communs aux 4 buckets)
        </h2>
        <div class="text-xs text-gray-500 font-mono">
            {{ $commonTargets }} target_at communs · variable {{ $variableLabels[$variable] }}
        </div>
    </div>

    @if ($commonTargets === 0)
        <div class="bg-gray-900/50 border border-gray-800 rounded-xl px-5 py-6 text-center text-sm text-gray-500">
            Aucun <code>target_at</code> n'est encore présent dans les 4 buckets simultanément
            avec une observation. Cela arrive si la collecte est récente (les buckets éloignés
            n'ont pas encore eu le temps d'archiver). Reviens dans quelques heures.
        </div>
    @else
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <table class="w-full text-xs font-mono">
                <thead class="bg-gray-950 text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left">Horizon</th>
                        <th class="px-3 py-2 text-right">n</th>
                        <th class="px-3 py-2 text-right">MAE A · legacy</th>
                        <th class="px-3 py-2 text-right">MAE B · amélioré</th>
                        <th class="px-3 py-2 text-right">MAE C · amélioré + fiabilité</th>
                        <th class="px-3 py-2 text-right">Gagnant</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach ($rowsCommon as $bucket => $mae)
                        @php $best = $bestKey($mae); @endphp
                        <tr class="hover:bg-gray-800/40">
                            <td class="px-3 py-2 text-gray-200">{{ $bucketLabels[$bucket] }}</td>
                            <td class="px-3 py-2 text-right text-gray-500">{{ $mae['n'] }}</td>
                            <td class="px-3 py-2 text-right {{ $best === 'a' ? 'text-emerald-300 font-semibold' : 'text-gray-300' }}">
                                {{ $fmt($mae['mae_a']) }}
                            </td>
                            <td class="px-3 py-2 text-right {{ $best === 'b' ? 'text-emerald-300 font-semibold' : 'text-gray-300' }}">
                                {{ $fmt($mae['mae_b']) }}
                            </td>
                            <td class="px-3 py-2 text-right {{ $best === 'c' ? 'text-emerald-300 font-semibold' : 'text-gray-300' }}">
                                {{ $fmt($mae['mae_c']) }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                @if ($best !== null)
                                    <span class="text-emerald-400">🏆 {{ strtoupper($best) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-3 py-2 text-[11px] text-gray-500 bg-gray-950/50 border-t border-gray-800">
                Unité : <strong>{{ $unit }}</strong>.
                MAE calculée sur les <strong>{{ $commonTargets }} créneaux</strong>
                présents dans les 4 buckets avec observation.
            </div>
        </div>
    @endif
</section>

{{-- ── Tableau "full" — référence sans filtre ── --}}
<section class="mb-8">
    <div class="flex items-baseline justify-between mb-3">
        <h2 class="text-sm font-semibold text-gray-300 uppercase tracking-wider flex items-center gap-2">
            <i class="fa-solid fa-layer-group text-gray-500"></i>
            Référence : MAE sur l'ensemble complet (par bucket, sans filtre)
        </h2>
        <div class="text-xs text-gray-500 font-mono">
            Affichée pour comparaison — pas apple-to-apple entre lignes
        </div>
    </div>

    <div class="bg-gray-900/60 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-xs font-mono">
            <thead class="bg-gray-950 text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left">Horizon</th>
                    <th class="px-3 py-2 text-right">target_at uniques</th>
                    <th class="px-3 py-2 text-right">n</th>
                    <th class="px-3 py-2 text-right">MAE A</th>
                    <th class="px-3 py-2 text-right">MAE B</th>
                    <th class="px-3 py-2 text-right">MAE C</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @foreach ($rowsFull as $bucket => $mae)
                    @php $best = $bestKey($mae); @endphp
                    <tr class="hover:bg-gray-800/40">
                        <td class="px-3 py-2 text-gray-300">{{ $bucketLabels[$bucket] }}</td>
                        <td class="px-3 py-2 text-right text-gray-500">{{ $fullTargets[$bucket] }}</td>
                        <td class="px-3 py-2 text-right text-gray-500">{{ $mae['n'] }}</td>
                        <td class="px-3 py-2 text-right {{ $best === 'a' ? 'text-emerald-300' : 'text-gray-400' }}">{{ $fmt($mae['mae_a']) }}</td>
                        <td class="px-3 py-2 text-right {{ $best === 'b' ? 'text-emerald-300' : 'text-gray-400' }}">{{ $fmt($mae['mae_b']) }}</td>
                        <td class="px-3 py-2 text-right {{ $best === 'c' ? 'text-emerald-300' : 'text-gray-400' }}">{{ $fmt($mae['mae_c']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

@endif
@endsection

@section('help')
<div class="text-sm space-y-3 text-gray-400">
    <h3 class="text-gray-200 font-semibold flex items-center gap-2">
        <i class="fa-solid fa-circle-info text-sky-400"></i> Lecture
    </h3>
    <p>
        Le tableau du haut compare les 4 horizons sur <strong>exactement les mêmes
        créneaux</strong> — c'est la seule vue qui mesure honnêtement la dégradation
        de la prévision quand l'horizon s'éloigne.
    </p>
    <p class="text-xs">
        Le tableau du bas, en référence, montre la MAE sur l'ensemble complet de
        chaque bucket. Les <code>n</code> et les MAE peuvent différer parce que :
    </p>
    <ul class="space-y-1 text-xs leading-relaxed list-disc list-inside">
        <li>les créneaux récents n'ont parfois que <code>nowcast</code> ;</li>
        <li>certains créneaux n'ont pas d'observation balise ;</li>
        <li>la collecte est récente — les buckets éloignés rattrapent doucement.</li>
    </ul>
    <p class="text-xs">
        Plus la collecte avance, plus le tableau du haut grossit (les target_at
        accumulent peu à peu les 4 buckets).
    </p>
    <h3 class="text-gray-200 font-semibold flex items-center gap-2 pt-2">
        <i class="fa-solid fa-lightbulb text-sky-400"></i> Attendu
    </h3>
    <p class="text-xs">
        La MAE doit globalement <strong>croître avec l'horizon</strong>
        (nowcast &lt; same_day &lt; J+1 &lt; J+2). Si ce n'est pas le cas sur le
        tableau du haut, c'est un signal — peut-être le biais d'archivage
        (le bucket <code>j_plus_2</code> capture en pratique ~49 h d'horizon,
        pas 72 h, cf. <code>FF_model_reliability.md</code> § *Limites*).
    </p>
</div>
@endsection
