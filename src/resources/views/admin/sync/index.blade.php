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

    </div>
</div>
@endsection
