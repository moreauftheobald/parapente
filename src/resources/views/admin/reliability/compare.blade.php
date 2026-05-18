@extends('layouts.admin')
@section('title', 'Fiabilité · Comparaison consensus')

@php
    use App\Services\Weather\Reliability\ConsensusCalculator;

    $variableLabels = [
        'wind_speed_avg' => 'Vent moyen (km/h)',
        'wind_speed_max' => 'Rafales (km/h)',
        'wind_direction' => 'Direction (°)',
    ];
    $isDir = ($variable ?? null) === 'wind_direction';

    // Tolérances de coloration d'erreur (par variable)
    $tolGreen  = $isDir ? 10.0 : 1.5;
    $tolOrange = $isDir ? 30.0 : 4.0;

    $errColor = function ($err) use ($tolGreen, $tolOrange) {
        if ($err === null) return 'text-gray-600';
        if ($err <= $tolGreen)  return 'text-emerald-300';
        if ($err <= $tolOrange) return 'text-amber-300';
        return 'text-red-300';
    };

    $fmtVal = function ($v) use ($isDir) {
        if ($v === null) return '—';
        return $isDir ? str_pad((string) (int) round((float) $v), 3, '0', STR_PAD_LEFT) . '°' : number_format((float) $v, 1);
    };

    $errOf = function ($consensus, $obs) use ($isDir) {
        if ($consensus === null || $obs === null) return null;
        return $isDir
            ? ConsensusCalculator::circularDistance((float) $consensus, (float) $obs)
            : abs((float) $consensus - (float) $obs);
    };

    $bucketLabels = [
        'nowcast'  => 'nowcast',
        'same_day' => 'same-day',
        'j_plus_1' => 'J+1',
        'j_plus_2' => 'J+2',
    ];

    $bestKey = function (array $mae): ?string {
        $vals = array_filter([
            'a' => $mae['mae_a'] ?? null,
            'b' => $mae['mae_b'] ?? null,
            'c' => $mae['mae_c'] ?? null,
        ], fn ($v) => $v !== null);
        if ($vals === []) return null;
        return array_keys($vals, min($vals))[0];
    };
@endphp

@section('content')
<x-admin.page-title
    title="Comparaison des consensus"
    subtitle="Phase 2.5 (shadow mode) — confronte 3 algorithmes (A legacy, B amélioré, C amélioré+fiabilité) à la vérité-terrain balise. Aucun impact sur le scoring de prod."
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
        <a href="{{ route('admin.reliability.export.compare-csv', ['balise' => $balise->id, 'variable' => $variable]) }}"
           class="text-xs text-emerald-400 hover:text-emerald-300 transition flex items-center gap-2"
           title="CSV filtré sur la balise et la variable courantes">
            <i class="fa-solid fa-file-csv"></i> Télécharger CSV
        </a>
        <a href="{{ route('admin.reliability.export.json') }}"
           class="text-xs text-sky-400 hover:text-sky-300 transition flex items-center gap-2"
           title="Export JSON complet (tous les paramètres + tous les datasets) — pour analyse externe">
            <i class="fa-solid fa-file-code"></i> Export JSON complet
        </a>
        <a href="{{ route('admin.reliability.models', ['balise' => $balise->id, 'variable' => $variable]) }}"
           class="text-xs text-gray-400 hover:text-sky-300 transition flex items-center gap-2">
            <i class="fa-solid fa-table-list"></i> Voir la fiabilité par modèle
        </a>
    </div>
</form>

{{-- Bandeau global : MAE A / B / C sur toutes les obs disponibles --}}
@php $best = $bestKey($globalStats); @endphp
<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-gradient-to-br from-gray-900 to-gray-950 border border-gray-800 rounded-xl p-4">
        <div class="text-[10px] uppercase tracking-wider text-gray-500 mb-1">Observations comparées</div>
        <div class="text-2xl font-semibold text-gray-100 font-mono">{{ $globalStats['n'] }}</div>
        <div class="text-[11px] text-gray-500 mt-1">{{ $variableLabels[$variable] }} · {{ $balise->name }}</div>
    </div>
    @foreach (['a' => 'A · legacy', 'b' => 'B · amélioré', 'c' => 'C · amélioré + fiabilité'] as $k => $label)
        @php $mae = $globalStats['mae_' . $k]; @endphp
        <div class="bg-gradient-to-br from-gray-900 to-gray-950 border rounded-xl p-4
                    @class([
                        'border-emerald-500/40 ring-1 ring-emerald-500/20' => $best === $k,
                        'border-gray-800' => $best !== $k,
                    ])">
            <div class="text-[10px] uppercase tracking-wider text-gray-500 mb-1 flex items-center gap-2">
                MAE {{ $label }}
                @if ($best === $k) <span class="text-emerald-400">🏆</span> @endif
            </div>
            <div class="text-2xl font-semibold font-mono
                @class([
                    'text-emerald-300' => $best === $k,
                    'text-gray-300'    => $best !== $k,
                ])">
                {{ $mae !== null ? number_format($mae, 2) : '—' }}
                <span class="text-xs text-gray-500 font-normal">{{ $isDir ? '°' : 'km/h' }}</span>
            </div>
        </div>
    @endforeach
</div>

{{-- Sections par horizon (today / J+1 / J+2) --}}
@foreach ($sections as $sectionKey => $section)
    @php $sBest = $bestKey($section['mae']); @endphp
    <section class="mb-8">
        <div class="flex items-baseline justify-between mb-3">
            <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider flex items-center gap-2">
                <i class="fa-solid fa-calendar-day text-sky-400"></i>
                {{ $section['label'] }}
            </h2>
            <div class="text-xs text-gray-500 flex items-center gap-3 font-mono">
                <span>n={{ $section['mae']['n'] }}</span>
                @foreach (['a','b','c'] as $k)
                    @php $m = $section['mae']['mae_' . $k]; @endphp
                    <span class="{{ $sBest === $k ? 'text-emerald-300 font-semibold' : 'text-gray-400' }}">
                        MAE {{ strtoupper($k) }} = {{ $m !== null ? number_format($m, 2) : '—' }}
                    </span>
                @endforeach
            </div>
        </div>

        @if (empty($section['rows']))
            <div class="bg-gray-900/50 border border-gray-800 rounded-xl px-5 py-6 text-center text-sm text-gray-500">
                Pas de tuples en base pour cet horizon.
            </div>
        @else
            <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
                <table class="w-full text-xs font-mono">
                    <thead class="bg-gray-950 text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Créneau</th>
                            <th class="px-3 py-2 text-left">Bucket</th>
                            <th class="px-3 py-2 text-right">A</th>
                            <th class="px-3 py-2 text-right">B</th>
                            <th class="px-3 py-2 text-right">C</th>
                            <th class="px-3 py-2 text-right">Observé</th>
                            <th class="px-3 py-2 text-right">ΔA</th>
                            <th class="px-3 py-2 text-right">ΔB</th>
                            <th class="px-3 py-2 text-right">ΔC</th>
                            <th class="px-3 py-2 text-right">Modèles</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @foreach ($section['rows'] as $row)
                            @php
                                $errA = $errOf($row->consensus_a, $row->observation);
                                $errB = $errOf($row->consensus_b, $row->observation);
                                $errC = $errOf($row->consensus_c, $row->observation);
                                $isPast = $row->target_at->isPast();
                            @endphp
                            <tr class="hover:bg-gray-800/40 {{ ! $isPast ? 'opacity-60' : '' }}">
                                <td class="px-3 py-1.5 text-gray-300">{{ $row->target_at->format('d/m H:i') }}</td>
                                <td class="px-3 py-1.5 text-gray-500">{{ $bucketLabels[$row->horizon_bucket] ?? $row->horizon_bucket }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-200">{{ $fmtVal($row->consensus_a) }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-200">{{ $fmtVal($row->consensus_b) }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-200">{{ $fmtVal($row->consensus_c) }}</td>
                                <td class="px-3 py-1.5 text-right text-sky-300 font-semibold">{{ $fmtVal($row->observation) }}</td>
                                <td class="px-3 py-1.5 text-right {{ $errColor($errA) }}">{{ $errA !== null ? number_format($errA, 1) : '—' }}</td>
                                <td class="px-3 py-1.5 text-right {{ $errColor($errB) }}">{{ $errB !== null ? number_format($errB, 1) : '—' }}</td>
                                <td class="px-3 py-1.5 text-right {{ $errColor($errC) }}">{{ $errC !== null ? number_format($errC, 1) : '—' }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-500">{{ $row->models_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endforeach

@endif
@endsection

@section('help')
<div class="text-sm space-y-3 text-gray-400">
    <h3 class="text-gray-200 font-semibold flex items-center gap-2">
        <i class="fa-solid fa-circle-info text-sky-400"></i> Lecture
    </h3>
    <p>Trois consensus calculés en parallèle sur les mêmes données, confrontés à l'observation balise.</p>
    <ul class="space-y-2 text-xs leading-relaxed">
        <li><strong class="text-gray-200">A · legacy</strong> — reproduction stricte de l'algo de prod (inverse-carré, EPSILON 0.001).</li>
        <li><strong class="text-gray-200">B · amélioré</strong> — EPSILON revu, filtrage MAD, médiane pondérée. Fiabilités égales.</li>
        <li><strong class="text-gray-200">C · amélioré + fiabilité</strong> — idem B, pondération par <code>weight_factor</code> du modèle.</li>
    </ul>
    <p class="text-xs">
        Les colonnes <strong>ΔA / ΔB / ΔC</strong> donnent l'écart absolu à l'observation
        (distance circulaire pour la direction). Vert ≤ {{ $isDir ?? false ? '10°' : '1.5 km/h' }},
        orange ≤ {{ $isDir ?? false ? '30°' : '4 km/h' }}, rouge au-delà. Le badge 🏆 marque
        l'algo de plus faible MAE sur la période.
    </p>
    <p class="text-xs">
        Les lignes <em>en pâle</em> correspondent à des créneaux futurs (pas encore observés).
    </p>
    <h3 class="text-gray-200 font-semibold flex items-center gap-2 pt-2">
        <i class="fa-solid fa-cog text-sky-400"></i> Réglages
    </h3>
    <p class="text-xs">
        Les paramètres (EPSILON, seuils MAD, switches) sont dans
        <a href="{{ route('admin.settings.index') }}" class="text-sky-400 hover:text-sky-300">/admin/settings</a>,
        section <em>Fiabilité des modèles</em>.
    </p>
</div>
@endsection
