@extends('layouts.admin')
@section('title', 'Sites')

@php
    /** Lien de tri sur l'en-tête (préserve les autres query params) */
    $sortLink = function (string $field, string $label) use ($sort, $dir) {
        $newDir = ($sort === $field && $dir === 'asc') ? 'desc' : 'asc';
        $arrow  = $sort === $field ? ($dir === 'asc' ? '↑' : '↓') : '';
        $params = array_merge(request()->query(), ['sort' => $field, 'dir' => $newDir]);
        return sprintf(
            '<a href="?%s" class="text-gray-300 hover:text-white">%s <span class="text-sky-400">%s</span></a>',
            http_build_query($params),
            e($label),
            $arrow
        );
    };

    $inputCls  = 'w-full mt-1 px-2 py-1 text-xs bg-gray-950 border border-gray-700 rounded text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $selectCls = $inputCls . ' cursor-pointer';
@endphp

@section('content')
<div class="max-w-7xl">
    <div class="flex items-baseline justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold text-white">Sites de vol</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ number_format($totalCount, 0, ',', ' ') }} site{{ $totalCount > 1 ? 's' : '' }} en base
                · {{ number_format($sites->total(), 0, ',', ' ') }} après filtre
            </p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.sites.map') }}"
               class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-200 text-xs font-medium rounded-md transition" title="Vue carte (activation/désactivation)">
                <i class="fa-solid fa-map-location-dot"></i> Vue carte
            </a>
            @if (request()->query())
                <a href="{{ route('admin.sites.index') }}"
                   class="text-xs text-gray-400 hover:text-white transition" title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </a>
            @endif
            <button form="filters-form" type="submit"
                    class="px-3 py-1.5 bg-sky-500 hover:bg-sky-400 text-white text-xs font-medium rounded-md transition">
                <i class="fa-solid fa-filter"></i> Appliquer
            </button>
        </div>
    </div>

    {{-- Form filtres "fantôme" : les inputs filtres dans les en-têtes du
         tableau lui sont rattachés via l'attribut HTML5 form="filters-form".
         Ça permet d'avoir des forms toggle/delete dans les lignes sans
         imbrication HTML interdite. --}}
    <form id="filters-form" method="GET"></form>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-950 text-xs uppercase tracking-wider border-b border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left align-top">
                        {!! $sortLink('name', 'Nom') !!}
                        <input form="filters-form" name="search" type="search"
                               value="{{ request('search') }}" placeholder="Rechercher…"
                               class="{{ $inputCls }}">
                    </th>
                    <th class="px-4 py-3 text-left align-top w-44">
                        {!! $sortLink('source', 'Source') !!}
                        <select form="filters-form" name="source" class="{{ $selectCls }}"
                                onchange="this.form.submit()">
                            <option value="">— toutes —</option>
                            @foreach ($sources as $s)
                                <option value="{{ $s }}" @selected(request('source') === $s)>{{ $s }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-left align-top w-40">
                        {!! $sortLink('region', 'Région') !!}
                        <select form="filters-form" name="region" class="{{ $selectCls }}"
                                onchange="this.form.submit()">
                            <option value="">— toutes —</option>
                            @foreach ($regions as $r)
                                <option value="{{ $r }}" @selected(request('region') === $r)>{{ $r }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-left align-top w-40">
                        {!! $sortLink('level', 'Niveau') !!}
                        <select form="filters-form" name="level" class="{{ $selectCls }}"
                                onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            @foreach (['debutant'=>'Débutant','intermediaire'=>'Intermédiaire','confirme'=>'Confirmé'] as $val => $lbl)
                                <option value="{{ $val }}" @selected(request('level') === $val)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24">
                        {!! $sortLink('altitude_m', 'Alt.') !!}
                    </th>
                    <th class="px-4 py-3 text-center align-top w-32">
                        {!! $sortLink('active', 'Statut') !!}
                        <select form="filters-form" name="active" class="{{ $selectCls }}"
                                onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            <option value="1" @selected(request('active') === '1')>Actifs</option>
                            <option value="0" @selected(request('active') === '0')>Inactifs</option>
                        </select>
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($sites as $site)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <div class="text-gray-100 font-medium">{{ $site->name }}</div>
                            <div class="text-xs text-gray-500 font-mono">{{ number_format($site->latitude, 4) }}, {{ number_format($site->longitude, 4) }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 text-xs rounded
                                @class([
                                    'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' => $site->source === 'manual',
                                    'bg-sky-500/15 text-sky-300 border border-sky-500/30'           => $site->source === 'paraglidingearth',
                                    'bg-gray-700 text-gray-300'                                       => ! in_array($site->source, ['manual','paraglidingearth']),
                                ])">
                                {{ $site->source }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-400">{{ $site->region ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-400">{{ ucfirst($site->level ?? '—') }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-400">
                            {{ $site->altitude_m !== null ? $site->altitude_m . ' m' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($site->active)
                                <i class="fa-solid fa-circle-check text-emerald-400 text-xl" title="Actif"></i>
                            @else
                                <i class="fa-solid fa-circle-xmark text-red-400 text-xl" title="Inactif"></i>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <a href="{{ route('admin.sites.edit', $site) }}"
                                   class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white transition"
                                   title="Éditer">
                                    <i class="fa-solid fa-pen text-xs"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.sites.toggle', $site) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded border transition
                                                @class([
                                                    'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $site->active,
                                                    'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $site->active,
                                                ])"
                                            title="{{ $site->active ? 'Désactiver' : 'Activer' }}">
                                        <i class="fa-solid fa-power-off text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-gray-500">
                            Aucun site ne correspond aux critères.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $sites->links() }}
    </div>
</div>
@endsection
