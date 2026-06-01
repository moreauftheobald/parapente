@extends('layouts.admin')
@section('title', 'Modèles météo')

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

    /** Format lisible de la fréquence : 60 → "1h", 360 → "6h", 720 → "12h" */
    $fmtFreq = function (int $minutes): string {
        if ($minutes < 60) return $minutes . ' min';
        if ($minutes % 60 === 0) return ($minutes / 60) . ' h';
        return floor($minutes / 60) . 'h' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    };
@endphp

@section('content')
<div class="max-w-7xl">
    <x-admin.page-title title="Modèles météo">
        <x-slot:subtitle>
            {{ $models->count() }} modèle{{ $models->count() > 1 ? 's' : '' }} configuré{{ $models->count() > 1 ? 's' : '' }}
            · {{ $models->where('active', true)->count() }} actif{{ $models->where('active', true)->count() > 1 ? 's' : '' }}
        </x-slot:subtitle>
        <x-slot:actions>
            @if (request()->query())
                <a href="{{ route('admin.models.index') }}" class="text-xs text-gray-400 hover:text-white transition" title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </a>
            @endif
            <x-admin.button form="filters-form" type="submit" variant="primary" size="sm" icon="fa-solid fa-filter">
                Appliquer
            </x-admin.button>
        </x-slot:actions>
    </x-admin.page-title>

    <form id="filters-form" method="GET"></form>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left align-top">
                        {!! $sortLink('name', 'Modèle') !!}
                        <input form="filters-form" name="search" type="search"
                               value="{{ request('search') }}" placeholder="Nom, code, provider…"
                               class="{{ $inputCls }}">
                    </th>
                    <th class="px-4 py-3 text-left align-top w-44">
                        {!! $sortLink('provider', 'Provider') !!}
                        <select form="filters-form" name="provider" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            @foreach ($providers as $p)
                                <option value="{{ $p }}" @selected(request('provider') === $p)>{{ $p }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">
                        {!! $sortLink('resolution_km', 'Résol.') !!}
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">
                        {!! $sortLink('max_horizon_h', 'Horizon') !!}
                    </th>
                    <th class="px-4 py-3 text-right align-top w-32 text-gray-300">
                        {!! $sortLink('weight_short', 'Poids') !!}
                        <div class="text-[10px] text-gray-500 normal-case mt-0.5">court / moyen</div>
                    </th>
                    <th class="px-4 py-3 text-right align-top w-20 text-gray-300">
                        {!! $sortLink('weight_factor', 'Fiabilité') !!}
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">
                        {!! $sortLink('refresh_frequency_minutes', 'Refresh') !!}
                    </th>
                    <th class="px-4 py-3 text-center align-top w-32">
                        {!! $sortLink('active', 'Statut') !!}
                        <select form="filters-form" name="active" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            <option value="1" @selected(request('active') === '1')>Actifs</option>
                            <option value="0" @selected(request('active') === '0')>Inactifs</option>
                        </select>
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($models as $m)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <div class="text-gray-100 font-medium">{{ $m->name }}</div>
                            <div class="text-xs text-gray-500 font-mono">{{ $m->code }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-300 text-sm">{{ $m->provider }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-400 text-xs">
                            {{ number_format((float) $m->resolution_km, 1) }} km
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-400 text-xs">
                            {{ $m->max_horizon_h }} h
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-400 text-xs">
                            <span class="text-emerald-300">{{ number_format((float) $m->weight_short, 2) }}</span>
                            <span class="text-gray-600 mx-0.5">/</span>
                            <span class="text-violet-300">{{ number_format((float) $m->weight_medium, 2) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-xs">
                            @php $wf = (float) $m->weight_factor; @endphp
                            <span @class([
                                'text-gray-500' => $wf === 1.0,
                                'text-amber-300' => $wf < 1.0 && $wf > 0,
                                'text-red-300' => $wf === 0.0,
                                'text-emerald-300' => $wf > 1.0,
                            ])>{{ number_format($wf, 2) }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-gray-400 text-xs">
                            {{ $fmtFreq($m->refresh_frequency_minutes) }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($m->active)
                                <i class="fa-solid fa-circle-check text-emerald-400 text-xl" title="Actif"></i>
                            @else
                                <i class="fa-solid fa-circle-xmark text-red-400 text-xl" title="Inactif"></i>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <a href="{{ route('admin.models.edit', $m) }}"
                                   class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white transition"
                                   title="Éditer">
                                    <i class="fa-solid fa-pen text-xs"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.models.toggle', $m) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded border transition
                                                @class([
                                                    'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $m->active,
                                                    'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $m->active,
                                                ])"
                                            title="{{ $m->active ? 'Désactiver' : 'Activer' }}">
                                        <i class="fa-solid fa-power-off text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-0 py-0">
                            <x-admin.empty-state icon="fa-solid fa-cloud" message="Aucun modèle." class="border-0 rounded-none" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
