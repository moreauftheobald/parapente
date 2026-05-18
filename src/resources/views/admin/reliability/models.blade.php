@extends('layouts.admin')
@section('title', 'Fiabilité · Par modèle')

@php
    $variableLabels = [
        'wind_speed_avg' => 'Vent moyen (km/h)',
        'wind_speed_max' => 'Rafales (km/h)',
        'wind_direction' => 'Direction (°)',
    ];
    $isDir = $variable === 'wind_direction';
    $unit  = $isDir ? '°' : 'km/h';

    $bucketLabels = [
        'nowcast'  => 'nowcast (0-6h)',
        'same_day' => 'same-day (6-24h)',
        'j_plus_1' => 'J+1 (24-48h)',
        'j_plus_2' => 'J+2 (48-72h)',
    ];

    // Coloration du weight_factor.
    //   factor < 0.5  → modèle nettement moins bon  → rouge
    //   factor < 0.9  → en dessous de la médiane    → ambre
    //   factor ≈ 1    → médian                       → gris
    //   factor > 1.1  → meilleur que la médiane     → sky
    //   factor > 1.5  → nettement meilleur          → emeraude
    $factorClass = function (?float $f): string {
        if ($f === null) return 'text-gray-600';
        if ($f >= 1.5)   return 'text-emerald-300 font-semibold';
        if ($f >  1.1)   return 'text-sky-300';
        if ($f >= 0.9)   return 'text-gray-300';
        if ($f >= 0.5)   return 'text-amber-300';
        return 'text-red-300';
    };

    $biasClass = function (?float $bias) use ($isDir): string {
        if ($bias === null) return 'text-gray-600';
        $threshold = $isDir ? 15.0 : 2.0;
        $abs = abs($bias);
        if ($abs <= $threshold * 0.5) return 'text-gray-400';
        if ($abs <= $threshold)       return 'text-amber-300';
        return 'text-red-300';
    };
@endphp

@section('content')
<x-admin.page-title
    title="Fiabilité par modèle"
    subtitle="MAE / RMSE / biais signé / weight_factor des modèles météo, fenêtre glissante (reliability.window_days)."
/>

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

{{-- Filtres + action --}}
<form method="GET" class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-5 flex flex-wrap items-end gap-4">
    <div class="flex flex-col gap-1">
        <label class="text-[11px] uppercase tracking-wider text-gray-400" for="balise">Balise</label>
        <select id="balise" name="balise"
                onchange="this.form.submit()"
                class="bg-gray-800 border border-gray-700 rounded px-3 py-1.5 text-sm text-gray-100 focus:outline-none focus:ring-1 focus:ring-sky-500">
            @foreach ($panel as $b)
                <option value="{{ $b->id }}" @selected($b->id === $baliseId)>{{ $b->name }}</option>
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
        @if ($lastComputedAt)
            <span class="text-[11px] text-gray-500 font-mono">
                Dernier calcul : {{ \Carbon\Carbon::parse($lastComputedAt)->format('d/m/Y H:i') }}
            </span>
        @endif
        <a href="{{ route('admin.reliability.export.reliability-csv', ['balise' => $baliseId, 'variable' => $variable]) }}"
           class="text-xs text-emerald-400 hover:text-emerald-300 transition flex items-center gap-2"
           title="CSV filtré sur la balise et la variable courantes">
            <i class="fa-solid fa-file-csv"></i> Télécharger CSV
        </a>
        <a href="{{ route('admin.reliability.export.json') }}"
           class="text-xs text-sky-400 hover:text-sky-300 transition flex items-center gap-2"
           title="Export JSON complet — pour analyse externe">
            <i class="fa-solid fa-file-code"></i> Export JSON complet
        </a>
        <a href="{{ route('admin.reliability.compare', ['balise' => $baliseId, 'variable' => $variable]) }}"
           class="text-xs text-gray-400 hover:text-sky-300 transition flex items-center gap-2">
            <i class="fa-solid fa-table"></i> Voir la comparaison des consensus
        </a>
    </div>
</form>

{{-- Bouton "Recalculer maintenant" — formulaire POST séparé --}}
<div class="flex items-center justify-end mb-4">
    <form method="POST" action="{{ route('admin.reliability.models.recompute') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('Recalculer la fiabilité (bloquant ~10 s) ?');"
                class="px-3 py-1.5 text-xs rounded border border-sky-500/40 text-sky-300 hover:bg-sky-500/10 transition flex items-center gap-2">
            <i class="fa-solid fa-rotate"></i> Recalculer maintenant
        </button>
    </form>
</div>

{{-- Tableau pivot --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
    <table class="w-full text-xs font-mono">
        <thead class="bg-gray-950 text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
            <tr>
                <th rowspan="2" class="px-3 py-2 text-left align-bottom border-r border-gray-800">Modèle</th>
                @foreach ($buckets as $bucket)
                    <th colspan="4" class="px-3 py-1 text-center border-r border-gray-800">
                        {{ $bucketLabels[$bucket] }}
                    </th>
                @endforeach
            </tr>
            <tr>
                @foreach ($buckets as $bucket)
                    <th class="px-2 py-1 text-right">MAE</th>
                    <th class="px-2 py-1 text-right">Biais</th>
                    <th class="px-2 py-1 text-right">Poids</th>
                    <th class="px-2 py-1 text-right border-r border-gray-800">n</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-800">
            @foreach ($pivot as $row)
                @php $model = $row['model']; @endphp
                <tr class="hover:bg-gray-800/40 {{ ! $model->active ? 'opacity-50' : '' }}">
                    <td class="px-3 py-1.5 border-r border-gray-800">
                        <div class="flex items-center gap-2">
                            <span class="text-gray-200">{{ $model->name }}</span>
                            @if (! $model->active)
                                <span class="text-[9px] uppercase text-gray-600 bg-gray-800 px-1 rounded">off</span>
                            @endif
                        </div>
                        <div class="text-[10px] text-gray-600">{{ $model->code }}</div>
                    </td>

                    @foreach ($buckets as $bucket)
                        @php $r = $row['buckets'][$bucket] ?? null; @endphp
                        <td class="px-2 py-1.5 text-right text-gray-300">
                            {{ $r && $r->mae !== null ? number_format((float) $r->mae, 2) : '—' }}
                        </td>
                        <td class="px-2 py-1.5 text-right {{ $biasClass($r?->bias_signed !== null ? (float) $r->bias_signed : null) }}">
                            @if ($r && $r->bias_signed !== null)
                                {{ ($r->bias_signed > 0 ? '+' : '') . number_format((float) $r->bias_signed, 2) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-2 py-1.5 text-right {{ $factorClass($r?->weight_factor !== null ? (float) $r->weight_factor : null) }}">
                            {{ $r && $r->weight_factor !== null ? number_format((float) $r->weight_factor, 2) : '—' }}
                        </td>
                        <td class="px-2 py-1.5 text-right text-gray-600 border-r border-gray-800">
                            {{ $r && $r->samples_n > 0 ? $r->samples_n : '—' }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@endif
@endsection

@section('help')
<div class="text-sm space-y-3 text-gray-400">
    <h3 class="text-gray-200 font-semibold flex items-center gap-2">
        <i class="fa-solid fa-circle-info text-sky-400"></i> Lecture
    </h3>
    <p>Pour chaque modèle météo, performance sur les ~7 derniers jours, agrégée par horizon de prévision.</p>
    <ul class="space-y-1 text-xs leading-relaxed">
        <li><strong class="text-gray-200">MAE</strong> — erreur moyenne absolue ({{ $unit ?? '' }}). Plus bas = mieux.</li>
        <li><strong class="text-gray-200">Biais</strong> — biais signé moyen. Positif = sur-estimation, négatif = sous-estimation.</li>
        <li><strong class="text-gray-200">Poids</strong> — <code>weight_factor</code> appliqué au consensus C. <code>1.0</code> = neutre / cold start, <code>2.0</code> = plafond, <code>0.25</code> = plancher.</li>
        <li><strong class="text-gray-200">n</strong> — nombre de paires (prévu, observé) ayant servi au calcul.</li>
    </ul>
    <p class="text-xs">
        Tant que <code>n</code> est sous le seuil <code>reliability.min_samples</code>
        (50 par défaut), le modèle reste neutre (poids 1.0) — affichage <code>—</code>.
    </p>
    <p class="text-xs">
        Le bouton « Recalculer maintenant » est utile après un changement de paramètre
        dans <a href="{{ route('admin.settings.index') }}" class="text-sky-400 hover:text-sky-300">/admin/settings</a>
        (window_days, min_samples, factor_min/max) — sinon le job tourne automatiquement à 03h30.
    </p>
</div>
@endsection
