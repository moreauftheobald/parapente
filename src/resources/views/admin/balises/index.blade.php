@extends('layouts.admin')
@section('title', 'Balises')

@php
    $sortLink = function (string $field, string $label) use ($sort, $dir) {
        $newDir = ($sort === $field && $dir === 'asc') ? 'desc' : 'asc';
        $arrow  = $sort === $field ? ($dir === 'asc' ? '↑' : '↓') : '';
        $params = array_merge(request()->query(), ['sort' => $field, 'dir' => $newDir]);
        return sprintf(
            '<a href="?%s" class="text-gray-300 hover:text-white">%s <span class="text-sky-400">%s</span></a>',
            http_build_query($params), e($label), $arrow
        );
    };

    $inputCls  = 'w-full mt-1 px-2 py-1 text-xs bg-gray-950 border border-gray-700 rounded text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $selectCls = $inputCls . ' cursor-pointer';

    /** Couleur du badge selon l'âge en minutes */
    $ageBadge = function (?\Carbon\Carbon $readAt): array {
        if (! $readAt) return ['—', 'bg-gray-800 text-gray-500 border-gray-700'];
        $min = abs(now()->diffInMinutes($readAt));
        if ($min < 30)  return [round($min) . " min",  'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'];
        if ($min < 120) return [round($min) . " min",  'bg-amber-500/15 text-amber-300 border-amber-500/30'];
        if ($min < 1440) return [round($min/60, 1) . " h", 'bg-red-500/15 text-red-300 border-red-500/30'];
        return [round($min/1440) . " j",  'bg-gray-700 text-gray-400 border-gray-700'];
    };
@endphp

@section('content')
<div class="max-w-7xl">
    <div class="flex items-baseline justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold text-white">Balises météo</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ number_format($totalCount, 0, ',', ' ') }} balise{{ $totalCount > 1 ? 's' : '' }} en base
                · {{ number_format($balises->total(), 0, ',', ' ') }} après filtre
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if (request()->query())
                <a href="{{ route('admin.balises.index') }}" class="text-xs text-gray-400 hover:text-white transition" title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </a>
            @endif
            <button form="filters-form" type="submit"
                    class="px-3 py-1.5 bg-sky-500 hover:bg-sky-400 text-white text-xs font-medium rounded-md transition">
                <i class="fa-solid fa-filter"></i> Appliquer
            </button>
        </div>
    </div>

    <form id="filters-form" method="GET"></form>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-950 text-xs uppercase tracking-wider border-b border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left align-top">
                        {!! $sortLink('name', 'Balise') !!}
                        <input form="filters-form" name="search" type="search"
                               value="{{ request('search') }}" placeholder="Nom ou external_id…"
                               class="{{ $inputCls }}">
                    </th>
                    <th class="px-4 py-3 text-left align-top w-36">
                        {!! $sortLink('source', 'Source') !!}
                        <select form="filters-form" name="source" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— toutes —</option>
                            @foreach ($sources as $s)
                                <option value="{{ $s }}" @selected(request('source') === $s)>{{ $s }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-left align-top w-44 text-gray-300">Dernière lecture</th>
                    <th class="px-4 py-3 text-left align-top w-40 text-gray-300">Vent / Temp.</th>
                    <th class="px-4 py-3 text-center align-top w-32">
                        {!! $sortLink('active', 'Statut') !!}
                        <select form="filters-form" name="active" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— toutes —</option>
                            <option value="1" @selected(request('active') === '1')>Actives</option>
                            <option value="0" @selected(request('active') === '0')>Inactives</option>
                        </select>
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($balises as $balise)
                    @php
                        $latest = $balise->latestReading;
                        [$ageLabel, $ageCls] = $ageBadge($latest?->read_at);
                    @endphp
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <div class="text-gray-100 font-medium">{{ $balise->name }}</div>
                            <div class="text-xs text-gray-500 font-mono">
                                #{{ $balise->external_id }}
                                · {{ number_format((float) $balise->latitude, 4) }}, {{ number_format((float) $balise->longitude, 4) }}
                                @if ($balise->altitude_m)
                                    · {{ $balise->altitude_m }}m
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 text-xs rounded
                                @class([
                                    'bg-sky-500/15 text-sky-300 border border-sky-500/30'         => $balise->source === 'pioupiou',
                                    'bg-violet-500/15 text-violet-300 border border-violet-500/30' => $balise->source === 'metar',
                                    'bg-amber-500/15 text-amber-300 border border-amber-500/30'   => $balise->source === 'holfuy',
                                    'bg-gray-800 text-gray-300 border border-gray-700'            => ! in_array($balise->source, ['pioupiou','metar','holfuy']),
                                ])">
                                {{ $balise->source }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if ($latest)
                                <div class="text-xs text-gray-400 font-mono">{{ $latest->read_at->format('d/m H:i') }}</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 text-[10px] rounded border {{ $ageCls }}">
                                    il y a {{ $ageLabel }}
                                </span>
                            @else
                                <span class="text-xs text-gray-600">Aucune</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs font-mono text-gray-400">
                            @if ($latest && $latest->wind_speed_avg !== null)
                                <div>
                                    <i class="fa-solid fa-wind text-emerald-400"></i>
                                    {{ number_format((float) $latest->wind_speed_avg, 1) }} km/h
                                    @if ($latest->wind_direction !== null)
                                        @ {{ $latest->wind_direction }}°
                                    @endif
                                </div>
                                @if ($latest->temperature !== null)
                                    <div class="text-gray-500 mt-0.5">
                                        <i class="fa-solid fa-temperature-half"></i>
                                        {{ number_format((float) $latest->temperature, 1) }}°C
                                    </div>
                                @endif
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($balise->active)
                                <i class="fa-solid fa-circle-check text-emerald-400 text-xl" title="Active"></i>
                            @else
                                <i class="fa-solid fa-circle-xmark text-red-400 text-xl" title="Inactive"></i>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <a href="{{ route('admin.balises.show', $balise) }}"
                                   class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white transition"
                                   title="Détail">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.balises.toggle', $balise) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded border transition
                                                @class([
                                                    'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $balise->active,
                                                    'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $balise->active,
                                                ])"
                                            title="{{ $balise->active ? 'Désactiver' : 'Activer' }}">
                                        <i class="fa-solid fa-power-off text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-gray-500">Aucune balise.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $balises->links() }}
    </div>
</div>
@endsection
