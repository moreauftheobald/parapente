@extends('layouts.admin')
@section('title', 'Sites')

@php
    /** Helper pour générer un lien de tri sur l'en-tête */
    $sortLink = function (string $field, string $label) use ($sort, $dir) {
        $newDir = ($sort === $field && $dir === 'asc') ? 'desc' : 'asc';
        $arrow  = $sort === $field ? ($dir === 'asc' ? '↑' : '↓') : '';
        $params = array_merge(request()->query(), ['sort' => $field, 'dir' => $newDir]);
        return sprintf(
            '<a href="?%s" class="hover:text-white">%s <span class="text-sky-400">%s</span></a>',
            http_build_query($params),
            e($label),
            $arrow
        );
    };
@endphp

@section('content')
<div class="max-w-7xl">
    <div class="flex items-baseline justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-white">Sites de vol</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ number_format($totalCount, 0, ',', ' ') }} site{{ $totalCount > 1 ? 's' : '' }} en base
                · {{ number_format($sites->total(), 0, ',', ' ') }} après filtre
            </p>
        </div>
    </div>

    {{-- Filtres ────────────────────────────────────────────────── --}}
    <form method="GET" class="bg-gray-900 border border-gray-800 rounded-xl p-4 mb-4 grid grid-cols-1 md:grid-cols-6 gap-3">
        <input type="text" name="search" placeholder="Rechercher un nom…"
               value="{{ request('search') }}"
               class="md:col-span-2 px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500">

        <select name="source" class="px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500">
            <option value="">Toutes sources</option>
            @foreach ($sources as $s)
                <option value="{{ $s }}" @selected(request('source') === $s)>{{ $s }}</option>
            @endforeach
        </select>

        <select name="active" class="px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500">
            <option value="">Tous statuts</option>
            <option value="1" @selected(request('active') === '1')>Actifs</option>
            <option value="0" @selected(request('active') === '0')>Inactifs</option>
        </select>

        <select name="level" class="px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500">
            <option value="">Tous niveaux</option>
            @foreach (['debutant','intermediaire','confirme'] as $lvl)
                <option value="{{ $lvl }}" @selected(request('level') === $lvl)>{{ ucfirst($lvl) }}</option>
            @endforeach
        </select>

        <select name="region" class="px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500">
            <option value="">Toutes régions</option>
            @foreach ($regions as $r)
                <option value="{{ $r }}" @selected(request('region') === $r)>{{ $r }}</option>
            @endforeach
        </select>

        <div class="md:col-span-6 flex gap-2">
            <button type="submit" class="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium rounded-md transition">
                Filtrer
            </button>
            @if (request()->query())
                <a href="{{ route('admin.sites.index') }}" class="px-4 py-2 border border-gray-700 hover:border-gray-500 text-gray-400 hover:text-gray-200 text-sm rounded-md transition">
                    Réinitialiser
                </a>
            @endif
        </div>
    </form>

    {{-- Tableau ────────────────────────────────────────────────── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-950 text-xs uppercase tracking-wider text-gray-400 border-b border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left">{!! $sortLink('name', 'Nom') !!}</th>
                    <th class="px-4 py-3 text-left">{!! $sortLink('source', 'Source') !!}</th>
                    <th class="px-4 py-3 text-left">{!! $sortLink('region', 'Région') !!}</th>
                    <th class="px-4 py-3 text-left">{!! $sortLink('level', 'Niveau') !!}</th>
                    <th class="px-4 py-3 text-right">{!! $sortLink('altitude_m', 'Alt.') !!}</th>
                    <th class="px-4 py-3 text-center">{!! $sortLink('active', 'Statut') !!}</th>
                    <th class="px-4 py-3 text-right">Actions</th>
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
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded bg-emerald-500/15 text-emerald-300 border border-emerald-500/30">● Actif</span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded bg-gray-800 text-gray-500 border border-gray-700">○ Inactif</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.sites.edit', $site) }}"
                               class="inline-block px-3 py-1 text-xs rounded border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white transition mr-1">
                                Éditer
                            </a>
                            <form method="POST" action="{{ route('admin.sites.toggle', $site) }}" class="inline">
                                @csrf
                                <button type="submit"
                                        class="px-3 py-1 text-xs rounded border transition
                                            @class([
                                                'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $site->active,
                                                'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $site->active,
                                            ])"
                                        title="{{ $site->active ? 'Désactiver' : 'Activer' }}">
                                    {{ $site->active ? 'Désactiver' : 'Activer' }}
                                </button>
                            </form>
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

    {{-- Pagination ─────────────────────────────────────────────── --}}
    <div class="mt-4">
        {{ $sites->links() }}
    </div>
</div>
@endsection
