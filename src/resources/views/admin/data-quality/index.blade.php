@extends('layouts.admin')
@section('title', 'Fusion / dédoublonnage')

@section('help')
    <div class="text-sm text-gray-400 leading-relaxed space-y-3">
        <p>
            Cette page liste les <strong>paires de doublons potentiels</strong> détectées
            sur la base des coordonnées géographiques.
        </p>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Sites</div>
            Une paire est retenue si distance ≤ seuil, écart d'altitude ≤ seuil
            <em>(si les deux sites le renseignent)</em>, et chevauchement
            d'orientation ≥ seuil <em>(si les deux ont des <code>site_conditions</code>)</em>.
        </div>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Balises</div>
            Une paire est retenue dès que la distance ≤ seuil. Les paires
            <em>intra-réseau</em> sont triées en premier (suspicion forte). Les paires
            <em>inter-réseaux</em> sont marquées « info » (souvent légitimes : pioupiou + METAR
            sur le même aéroport).
        </div>
        <p class="text-xs text-gray-500 mt-3 pt-3 border-t border-gray-800">
            Les seuils s'éditent dans <a href="{{ route('admin.settings.index') }}" class="text-sky-400 hover:text-sky-300">Paramètres généraux</a>
            (groupe « Qualité des données »).
        </p>
        <p class="text-xs text-gray-500">
            Ignorer une paire la cache de la liste par défaut ; on peut la
            réafficher avec « Voir aussi les paires ignorées ».
        </p>
    </div>
@endsection

@section('content')
@php
    $btnIcon = 'inline-flex items-center justify-center w-7 h-7 rounded text-xs transition';
    $btnPrim = 'inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium rounded transition';
    $sourceLabel = ['pioupiou' => 'PiouPiou', 'metar' => 'METAR', 'ffvl' => 'FFVL', 'windguru' => 'Windguru', 'netatmo' => 'Netatmo'];

    $entityCard = function ($entity, string $type) {
        return [
            'name'   => $entity->name,
            'meta'   => $type === 'site'
                ? ($entity->region ?: '—') . ' · ' . ($entity->altitude_m !== null ? $entity->altitude_m . ' m' : 'alt. ?')
                : strtoupper($entity->source) . ' · #' . $entity->external_id,
            'coords' => number_format((float) $entity->latitude, 5, '.', '') . ', ' . number_format((float) $entity->longitude, 5, '.', ''),
            'active' => (bool) $entity->active,
            'id'     => $entity->id,
        ];
    };
@endphp

<div>
    <x-admin.page-title title="Fusion / dédoublonnage"
        subtitle="Doublons potentiels détectés sur la base des coordonnées géographiques.">
        <x-slot:actions>
            <form method="GET" action="{{ route('admin.data-quality.index') }}" class="flex items-center gap-2">
                <label class="inline-flex items-center gap-2 text-xs text-gray-400 cursor-pointer">
                    <input type="checkbox" name="show_ignored" value="1" @checked($showIgnored)
                           onchange="this.form.submit()"
                           class="w-3.5 h-3.5 rounded border-gray-600 bg-gray-900 text-sky-500 focus:ring-sky-500/40">
                    Voir aussi les paires ignorées
                </label>
            </form>
        </x-slot:actions>
    </x-admin.page-title>


    {{-- ── Sites ─────────────────────────────────────────────────── --}}
    <section class="mt-6 bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <header class="px-5 py-3 border-b border-gray-800 flex items-baseline justify-between">
            <h2 class="text-lg font-medium text-white">
                <i class="fa-solid fa-mountain-sun text-emerald-400"></i> Sites
            </h2>
            <span class="text-xs text-gray-500">
                {{ count($sitePairs) }} paire{{ count($sitePairs) > 1 ? 's' : '' }}
            </span>
        </header>

        @if (empty($sitePairs))
            <div class="px-5 py-10 text-center text-sm text-gray-500">
                Aucune paire détectée selon les seuils en vigueur. ✨
            </div>
        @else
            <ul class="divide-y divide-gray-800">
                @foreach ($sitePairs as $p)
                    @php
                        $a = $entityCard($p['a'], 'site');
                        $b = $entityCard($p['b'], 'site');
                    @endphp
                    <li class="px-5 py-4 {{ $p['ignored'] ? 'opacity-60' : '' }}">
                        <div class="flex items-center justify-between mb-3 text-xs text-gray-400 flex-wrap gap-2">
                            <div class="flex items-center gap-3">
                                <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-300">
                                    <i class="fa-solid fa-arrows-left-right-to-line"></i> {{ $p['distance_m'] }} m
                                </span>
                                @if ($p['altitude_diff_m'] !== null)
                                    <span><i class="fa-solid fa-arrows-up-down"></i> Δalt {{ $p['altitude_diff_m'] }} m</span>
                                @else
                                    <span class="text-gray-600">Δalt non comparable</span>
                                @endif
                                @if ($p['orientation_overlap_pct'] !== null)
                                    <span><i class="fa-solid fa-compass"></i> orientation {{ $p['orientation_overlap_pct'] }}%</span>
                                @else
                                    <span class="text-gray-600">orientation non comparable</span>
                                @endif
                                @if ($p['ignored'])
                                    <span class="px-2 py-0.5 rounded bg-amber-500/15 border border-amber-500/30 text-amber-300">
                                        <i class="fa-solid fa-eye-slash"></i> ignorée
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($p['ignored'])
                                    <form method="POST" action="{{ route('admin.data-quality.unignore', $p['ignored_id']) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="{{ $btnPrim }} bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30"
                                                title="Restaurer la paire dans la liste">
                                            <i class="fa-solid fa-rotate-left"></i> Restaurer
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.data-quality.ignore') }}">
                                        @csrf
                                        <input type="hidden" name="entity_type" value="site">
                                        <input type="hidden" name="entity_a_id" value="{{ $a['id'] }}">
                                        <input type="hidden" name="entity_b_id" value="{{ $b['id'] }}">
                                        <button type="submit" class="{{ $btnPrim }} bg-gray-800 hover:bg-gray-700 text-gray-300 border border-gray-700"
                                                title="Marquer la paire comme non pertinente">
                                            <i class="fa-solid fa-eye-slash"></i> Ignorer
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            @foreach ([$a, $b] as $idx => $card)
                                <div class="bg-gray-950/50 border border-gray-800 rounded p-3 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('admin.sites.edit', $card['id']) }}"
                                               class="text-sm font-medium text-white hover:text-sky-300 truncate">
                                                {{ $card['name'] }}
                                            </a>
                                            @if ($card['active'])
                                                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-300">actif</span>
                                            @else
                                                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-gray-700/50 text-gray-400">inactif</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ $card['meta'] }}</div>
                                        <div class="text-xs text-gray-600 font-mono mt-0.5">{{ $card['coords'] }}</div>
                                    </div>
                                    @if ($card['active'] && ! $p['ignored'])
                                        <form method="POST" action="{{ route('admin.sites.toggle', $card['id']) }}">
                                            @csrf
                                            <button type="submit" class="{{ $btnPrim }} bg-red-500/20 hover:bg-red-500/30 text-red-300 border border-red-500/30 whitespace-nowrap">
                                                <i class="fa-solid fa-power-off"></i> Désactiver
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- ── Balises ───────────────────────────────────────────────── --}}
    <section class="mt-6 bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <header class="px-5 py-3 border-b border-gray-800 flex items-baseline justify-between">
            <h2 class="text-lg font-medium text-white">
                <i class="fa-solid fa-tower-broadcast text-sky-400"></i> Balises
            </h2>
            <span class="text-xs text-gray-500">
                {{ count($balisePairs) }} paire{{ count($balisePairs) > 1 ? 's' : '' }}
            </span>
        </header>

        @if (empty($balisePairs))
            <div class="px-5 py-10 text-center text-sm text-gray-500">
                Aucune paire détectée selon les seuils en vigueur. ✨
            </div>
        @else
            <ul class="divide-y divide-gray-800">
                @foreach ($balisePairs as $p)
                    @php
                        $a = $entityCard($p['a'], 'balise');
                        $b = $entityCard($p['b'], 'balise');
                    @endphp
                    <li class="px-5 py-4 {{ $p['ignored'] ? 'opacity-60' : '' }}">
                        <div class="flex items-center justify-between mb-3 text-xs text-gray-400 flex-wrap gap-2">
                            <div class="flex items-center gap-3">
                                <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-300">
                                    <i class="fa-solid fa-arrows-left-right-to-line"></i> {{ $p['distance_m'] }} m
                                </span>
                                @if ($p['same_network'])
                                    <span class="px-2 py-0.5 rounded bg-red-500/15 border border-red-500/30 text-red-300">
                                        <i class="fa-solid fa-triangle-exclamation"></i> même réseau
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded bg-sky-500/15 border border-sky-500/30 text-sky-300">
                                        <i class="fa-solid fa-circle-info"></i> réseaux différents
                                    </span>
                                @endif
                                @if ($p['ignored'])
                                    <span class="px-2 py-0.5 rounded bg-amber-500/15 border border-amber-500/30 text-amber-300">
                                        <i class="fa-solid fa-eye-slash"></i> ignorée
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($p['ignored'])
                                    <form method="POST" action="{{ route('admin.data-quality.unignore', $p['ignored_id']) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="{{ $btnPrim }} bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30">
                                            <i class="fa-solid fa-rotate-left"></i> Restaurer
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.data-quality.ignore') }}">
                                        @csrf
                                        <input type="hidden" name="entity_type" value="balise">
                                        <input type="hidden" name="entity_a_id" value="{{ $a['id'] }}">
                                        <input type="hidden" name="entity_b_id" value="{{ $b['id'] }}">
                                        <button type="submit" class="{{ $btnPrim }} bg-gray-800 hover:bg-gray-700 text-gray-300 border border-gray-700">
                                            <i class="fa-solid fa-eye-slash"></i> Ignorer
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            @foreach ([$a, $b] as $idx => $card)
                                <div class="bg-gray-950/50 border border-gray-800 rounded p-3 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('admin.balises.show', $card['id']) }}"
                                               class="text-sm font-medium text-white hover:text-sky-300 truncate">
                                                {{ $card['name'] }}
                                            </a>
                                            @if ($card['active'])
                                                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-300">active</span>
                                            @else
                                                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded bg-gray-700/50 text-gray-400">inactive</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ $card['meta'] }}</div>
                                        <div class="text-xs text-gray-600 font-mono mt-0.5">{{ $card['coords'] }}</div>
                                    </div>
                                    @if ($card['active'] && ! $p['ignored'])
                                        <form method="POST" action="{{ route('admin.balises.toggle', $card['id']) }}">
                                            @csrf
                                            <button type="submit" class="{{ $btnPrim }} bg-red-500/20 hover:bg-red-500/30 text-red-300 border border-red-500/30 whitespace-nowrap">
                                                <i class="fa-solid fa-power-off"></i> Désactiver
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
