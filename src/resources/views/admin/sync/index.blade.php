@extends('layouts.admin')
@section('title', 'Synchronisation')

@php
    $inputCls  = 'w-full mt-1 px-2 py-1.5 text-sm bg-gray-950 border border-gray-700 rounded text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $labelCls  = 'block text-xs uppercase tracking-wider text-gray-500';
    $btnCls    = 'mt-4 inline-flex items-center gap-2 px-4 py-2 bg-sky-500 hover:bg-sky-400 disabled:opacity-50 disabled:cursor-wait text-white text-sm font-medium rounded-md transition';
@endphp

@section('content')
<div class="max-w-4xl">
    <h1 class="text-2xl font-semibold text-white mb-1">Synchronisation des données</h1>
    <p class="text-sm text-gray-500 mb-6">
        Déclenche manuellement l'import des sites de vol (ParaglidingEarth) et la découverte
        des balises météo (PiouPiou, METAR). Opérations idempotentes : relancer met à jour
        l'existant et ajoute les nouveautés, sans rien désactiver. L'exécution est synchrone
        et peut prendre jusqu'à une minute.
    </p>

    @if (session('sync_output'))
        <div class="mb-6 bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <div class="px-4 py-2 text-xs uppercase tracking-wider text-gray-500 border-b border-gray-800">
                Résultat de la dernière opération
            </div>
            <pre class="px-4 py-3 text-xs text-gray-300 whitespace-pre-wrap font-mono leading-relaxed">{{ session('sync_output') }}</pre>
        </div>
    @endif

    @if ($report = session('deploy_report'))
        @php
            $loc   = $report['location'];
            $sites = $report['sites'];
            $bals  = $report['balises'];
            $sourceLabel = ['pioupiou' => 'PiouPiou', 'metar' => 'METAR (NOAA)', 'windy' => 'Windy.com'];
        @endphp
        <div class="mb-6 bg-emerald-500/10 border border-emerald-500/30 rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-emerald-500/20 flex items-baseline justify-between flex-wrap gap-2">
                <h3 class="text-sm font-semibold text-emerald-300">
                    <i class="fa-solid fa-circle-check"></i>
                    Déploiement terminé — {{ $loc['name'] }}@if ($loc['admin1']), {{ $loc['admin1'] }}@endif ({{ $loc['country'] }})
                </h3>
                <span class="text-xs text-gray-400">
                    rayon {{ rtrim(rtrim(number_format((float) $report['radius_km'], 1, ',', ' '), '0'), ',') }}&nbsp;km
                    · centre {{ number_format((float) $loc['latitude'], 4, ',', '') }}, {{ number_format((float) $loc['longitude'], 4, ',', '') }}
                </span>
            </div>
            <div class="px-4 py-4 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                <div class="bg-gray-950/40 border border-gray-800 rounded p-3">
                    <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">⛰ Sites</div>
                    <div class="text-2xl font-semibold text-white">{{ $sites['newly_activated'] }}</div>
                    <div class="text-xs text-gray-400">
                        nouvellement activé{{ $sites['newly_activated'] > 1 ? 's' : '' }}
                        · {{ $sites['already_active'] }} déjà actif{{ $sites['already_active'] > 1 ? 's' : '' }}
                        · {{ $sites['in_zone'] }} dans la zone
                    </div>
                </div>
                @foreach ($bals as $key => $b)
                    <div class="bg-gray-950/40 border border-gray-800 rounded p-3">
                        <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">
                            🪁 Balises {{ $sourceLabel[$key] ?? $key }}
                        </div>
                        <div class="text-2xl font-semibold text-white">{{ $b['newly_activated'] }}</div>
                        <div class="text-xs text-gray-400">
                            nouvellement activée{{ $b['newly_activated'] > 1 ? 's' : '' }}
                            ({{ $b['created'] }} créée{{ $b['created'] > 1 ? 's' : '' }})
                            · {{ $b['already_active'] }} déjà active{{ $b['already_active'] > 1 ? 's' : '' }}
                            @if ($b['kept_off'] > 0)
                                · <span class="text-amber-400">{{ $b['kept_off'] }} laissée{{ $b['kept_off'] > 1 ? 's' : '' }} off (désactivation manuelle respectée)</span>
                            @endif
                            · {{ $b['in_zone'] }} dans la zone ({{ $b['discovered'] }} découverte{{ $b['discovered'] > 1 ? 's' : '' }} dans la bbox)
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 px-4 py-2 rounded bg-red-500/15 border border-red-500/30 text-red-300 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6">

        {{-- ── Déploiement géographique (sites + balises en une fois) ── --}}
        <div class="bg-gray-900 border border-sky-500/30 rounded-xl p-5">
            <div class="flex items-start justify-between mb-1 flex-wrap gap-2">
                <h2 class="text-lg font-medium text-white">🌍 Déploiement géographique</h2>
                <span class="text-xs text-gray-500">sites + balises dans un rayon autour d'une ville</span>
            </div>
            <p class="text-sm text-gray-500 mb-4">
                Géocode la ville (Open-Meteo), puis active <strong class="text-gray-400">tous les sites</strong>
                déjà en base situés dans le rayon, et <strong class="text-gray-400">découvre + active</strong>
                toutes les balises (PiouPiou, METAR, Windy si clé API configurée) du périmètre. Cumulatif : rien
                n'est désactivé hors zone. Les balises désactivées manuellement restent off.
            </p>
            <form method="POST" action="{{ route('admin.sync.deploy') }}"
                  onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Déploiement en cours…';">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label for="city" class="{{ $labelCls }}">Ville</label>
                        <input type="text" name="city" id="city" required
                               value="{{ old('city') }}"
                               placeholder="ex. Annecy, Chamonix, Millau…"
                               class="{{ $inputCls }}">
                    </div>
                    <div>
                        <label for="radius_km" class="{{ $labelCls }}">Rayon (km)</label>
                        <input type="number" name="radius_km" id="radius_km" min="1" max="500" step="1"
                               value="{{ old('radius_km', 50) }}" required
                               class="{{ $inputCls }}">
                    </div>
                </div>
                <button type="submit" class="{{ $btnCls }}">
                    <i class="fa-solid fa-rocket"></i> Lancer le déploiement
                </button>
            </form>
        </div>

        {{-- ── Sites ParaglidingEarth ─────────────────────────────── --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-start justify-between mb-1">
                <h2 class="text-lg font-medium text-white">⛰ Sites de vol — ParaglidingEarth</h2>
                <span class="text-xs text-gray-500">
                    {{ number_format($sitesPge, 0, ',', ' ') }} importé{{ $sitesPge > 1 ? 's' : '' }}
                    · {{ number_format($sitesTotal, 0, ',', ' ') }} au total
                </span>
            </div>
            <p class="text-sm text-gray-500 mb-4">
                Importe les décollages parapente d'un pays. Les nouveaux sites sont créés
                <strong class="text-gray-400">inactifs</strong> : il faut les activer manuellement
                dans la section Sites.
            </p>
            <form method="POST" action="{{ route('admin.sync.sites') }}" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Import en cours…';">
                @csrf
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="iso" class="{{ $labelCls }}">Pays</label>
                        <select name="iso" id="iso" class="{{ $inputCls }} cursor-pointer">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}" @selected(old('iso', 'fr') === $code)>{{ $name }} ({{ strtoupper($code) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="limit" class="{{ $labelCls }}">Limite (option)</label>
                        <input type="number" name="limit" id="limit" min="1" max="20000"
                               value="{{ old('limit') }}" placeholder="toutes"
                               class="{{ $inputCls }}">
                    </div>
                </div>
                <button type="submit" class="{{ $btnCls }}">
                    <i class="fa-solid fa-cloud-arrow-down"></i> Importer les sites
                </button>
            </form>
        </div>

        {{-- ── Balises PiouPiou ───────────────────────────────────── --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-start justify-between mb-1">
                <h2 class="text-lg font-medium text-white">🪁 Balises — PiouPiou</h2>
                <span class="text-xs text-gray-500">{{ number_format($balisesPiou, 0, ',', ' ') }} en base</span>
            </div>
            <p class="text-sm text-gray-500 mb-4">
                Découvre les stations PiouPiou actives ayant émis dans les dernières 24&nbsp;h,
                à l'intérieur de la zone géographique ci-dessous.
            </p>
            <form method="POST" action="{{ route('admin.sync.balises') }}" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Découverte en cours…';">
                @csrf
                <input type="hidden" name="source" value="pioupiou">
                @include('admin.sync._bbox')
                <button type="submit" class="{{ $btnCls }}">
                    <i class="fa-solid fa-tower-broadcast"></i> Découvrir les balises PiouPiou
                </button>
            </form>
        </div>

        {{-- ── Balises METAR ──────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-start justify-between mb-1">
                <h2 class="text-lg font-medium text-white">✈ Balises — METAR (NOAA)</h2>
                <span class="text-xs text-gray-500">{{ number_format($balisesMetar, 0, ',', ' ') }} en base</span>
            </div>
            <p class="text-sm text-gray-500 mb-4">
                Découvre les stations aéroportuaires (observations METAR) présentes dans la zone
                géographique ci-dessous.
            </p>
            <form method="POST" action="{{ route('admin.sync.balises') }}" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Découverte en cours…';">
                @csrf
                <input type="hidden" name="source" value="metar">
                @include('admin.sync._bbox')
                <button type="submit" class="{{ $btnCls }}">
                    <i class="fa-solid fa-tower-broadcast"></i> Découvrir les balises METAR
                </button>
            </form>
        </div>

        {{-- ── Balises Windy.com ──────────────────────────────────── --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <div class="flex items-start justify-between mb-1">
                <h2 class="text-lg font-medium text-white">🌬 Balises — Windy.com (Open Data)</h2>
                <span class="text-xs text-gray-500">{{ number_format($balisesWindy, 0, ',', ' ') }} en base</span>
            </div>
            <p class="text-sm text-gray-500 mb-4">
                Découvre les stations Windy publiées sous licence ouverte dans la zone géographique
                ci-dessous (Stations API v2). Chaque réseau amont (Holfuy, Davis, Netatmo…) dont le
                propriétaire a opté pour le partage ouvert apparaît ici.
            </p>
            @if (! $windyKeyConfigured)
                <div class="mb-4 px-3 py-2 rounded bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs">
                    <i class="fa-solid fa-key"></i>
                    Clé API Windy non configurée. Renseigne-la dans
                    <a href="{{ route('admin.settings.index') }}" class="underline hover:text-amber-200">
                        Paramètres généraux → Sources balises
                    </a>
                    avant de lancer la découverte.
                </div>
            @endif
            <form method="POST" action="{{ route('admin.sync.balises') }}" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Découverte en cours…';">
                @csrf
                <input type="hidden" name="source" value="windy">
                @include('admin.sync._bbox')
                <button type="submit" class="{{ $btnCls }}" @disabled(! $windyKeyConfigured)>
                    <i class="fa-solid fa-tower-broadcast"></i> Découvrir les balises Windy
                </button>
            </form>
        </div>

    </div>
</div>
@endsection
