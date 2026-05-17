@extends('layouts.admin')
@section('title', 'Trafic')

@section('help')
    <div class="text-sm text-gray-400 leading-relaxed space-y-3">
        <p>
            Tableau de bord de fréquentation alimenté par le middleware
            <code>RecordPageView</code> sur le groupe <code>web</code>.
        </p>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Privacy by design</div>
            Aucun cookie posé, aucun identifiant persistant. Le <em>visitor
            hash</em> est dérivé de <code>ip + UA + jour + APP_KEY</code> en
            SHA-256, donc rotatif à minuit — pas de suivi inter-jour.
            Hors RGPD obligatoire, pas de bannière de consentement nécessaire.
        </div>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Périmètre compté</div>
            Seules les pages Blade publiques (groupe <code>web</code>). Les
            requêtes <code>/api/*</code>, <code>/admin/*</code>, AJAX, assets
            et erreurs 4xx/5xx sont écartées. Les bots sont enregistrés mais
            filtrés des KPI « humains ».
        </div>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Rétention</div>
            Configurable dans <a href="{{ route('admin.settings.index') }}" class="text-sky-400 hover:text-sky-300">Paramètres → Trafic / analytics</a>.
            Purge nocturne automatique (<code>PurgePageViewsJob</code>, 03h15).
        </div>
    </div>
@endsection

@section('content')
@php
    /** Helpers Blade locaux pour le rendu SVG/UX */
    $fmt = fn (int $n) => number_format($n, 0, ',', ' ');
    $delta = function (?int $pct): string {
        if ($pct === null) return '<span class="text-gray-500 text-xs">—</span>';
        $cls = $pct > 0 ? 'text-emerald-400' : ($pct < 0 ? 'text-red-400' : 'text-gray-400');
        $ico = $pct > 0 ? 'fa-arrow-trend-up' : ($pct < 0 ? 'fa-arrow-trend-down' : 'fa-minus');
        return "<span class=\"{$cls} text-xs font-medium\"><i class=\"fa-solid {$ico}\"></i> "
             . ($pct > 0 ? '+' : '') . $pct . '%</span>';
    };
    /** [Label, icône FA, classe texte FA, classe fond barre] — classes statiques pour le JIT Tailwind */
    $deviceLabel = [
        'desktop' => ['Bureau',   'fa-desktop',             'text-sky-400',     'bg-sky-500'],
        'mobile'  => ['Mobile',   'fa-mobile-screen',       'text-emerald-400', 'bg-emerald-500'],
        'tablet'  => ['Tablette', 'fa-tablet-screen-button','text-violet-400',  'bg-violet-500'],
        'bot'     => ['Bot',      'fa-robot',               'text-gray-400',    'bg-gray-500'],
        'unknown' => ['Inconnu',  'fa-circle-question',     'text-gray-400',    'bg-gray-500'],
    ];

    $totalDevices = array_sum(array_column($devices, 'visits')) ?: 1;
    $totalOs       = array_sum(array_column($os, 'visits')) ?: 1;
    $totalBrowsers = array_sum(array_column($browsers, 'visits')) ?: 1;

    $maxHour = max(1, max(array_column($hourlyToday, 'visits')));
    $maxDay  = max(1, max(array_column($dailyMonth, 'visits')));
@endphp

<div class="max-w-6xl">
    <x-admin.page-title title="Trafic du site"
        :subtitle="'Dernière mise à jour : ' . $now->translatedFormat('l j F · H:i') . ' · Fuseau Europe/Paris'">
        <x-slot:actions>
            <a href="{{ route('admin.settings.index') }}#analytics"
               class="text-xs text-gray-500 hover:text-sky-300 transition">
                <i class="fa-solid fa-gear"></i> Configurer la rétention
            </a>
        </x-slot:actions>
    </x-admin.page-title>

    {{-- ── KPI tiles ─────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        @foreach ([
            'today'    => ['Aujourd\'hui',        'fa-calendar-day',  'vs hier (à heure égale)'],
            'last_7d'  => ['7 derniers jours',     'fa-calendar-week', 'vs 7 j précédents'],
            'last_30d' => ['30 derniers jours',    'fa-calendar',      'vs 30 j précédents'],
        ] as $k => [$label, $icon, $caption])
            @php $row = $kpis[$k]; @endphp
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <div class="flex items-center justify-between mb-3">
                    <div class="text-xs font-medium uppercase tracking-wider text-gray-500">
                        <i class="fa-solid {{ $icon }}"></i> {{ $label }}
                    </div>
                    {!! $delta($row['delta_pct']) !!}
                </div>
                <div class="flex items-baseline gap-4">
                    <div>
                        <div class="text-3xl font-semibold text-white">{{ $fmt($row['visits']) }}</div>
                        <div class="text-xs text-gray-500 mt-1">visites</div>
                    </div>
                    <div class="border-l border-gray-800 pl-4">
                        <div class="text-2xl font-medium text-sky-400">{{ $fmt($row['uniques']) }}</div>
                        <div class="text-xs text-gray-500 mt-1">uniques</div>
                    </div>
                </div>
                <div class="text-[11px] text-gray-600 mt-3 pt-3 border-t border-gray-800">{{ $caption }}</div>
            </div>
        @endforeach
    </div>

    {{-- ── Graphe horaire (aujourd'hui) ──────────────────────────── --}}
    <section class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-4 flex items-center gap-2">
            <i class="fa-solid fa-clock"></i> Visites par heure aujourd'hui
        </h2>
        @php
            $chartH = 160;
            $barW   = 100 / 24;
        @endphp
        {{-- SVG des barres uniquement (étirement horizontal accepté pour des rectangles).
             Les labels sont rendus en HTML positionné en % pour éviter la déformation
             due à preserveAspectRatio="none". --}}
        <div class="relative">
            <svg viewBox="0 0 100 {{ $chartH }}" preserveAspectRatio="none" class="w-full h-40 block">
                @foreach ([0.25, 0.5, 0.75] as $f)
                    <line x1="0" y1="{{ $chartH * (1 - $f) }}" x2="100" y2="{{ $chartH * (1 - $f) }}"
                          stroke="#1f2937" stroke-width="0.4" stroke-dasharray="0.8,0.8"/>
                @endforeach
                @foreach ($hourlyToday as $i => $row)
                    @php
                        $h = $chartH * ($row['visits'] / $maxHour);
                        $y = $chartH - $h;
                    @endphp
                    <rect x="{{ $i * $barW + 0.3 }}" y="{{ $y }}"
                          width="{{ $barW - 0.6 }}" height="{{ $h }}"
                          fill="{{ $row['visits'] > 0 ? '#0ea5e9' : '#374151' }}"
                          opacity="0.85">
                        <title>{{ $row['hour'] }}h : {{ $fmt($row['visits']) }} visite{{ $row['visits'] > 1 ? 's' : '' }} · {{ $fmt($row['uniques']) }} unique{{ $row['uniques'] > 1 ? 's' : '' }}</title>
                    </rect>
                @endforeach
            </svg>
            {{-- Labels en HTML : positionnés au centre de chaque barre cible. --}}
            <div class="relative h-5 mt-1 text-[11px] text-gray-500 font-mono select-none">
                @foreach ([0, 6, 12, 18, 23] as $h)
                    @php $left = $h * $barW + $barW / 2; @endphp
                    <span class="absolute -translate-x-1/2 top-0" style="left: {{ $left }}%;">{{ $h }}h</span>
                @endforeach
            </div>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            Survole une barre pour voir le détail. Total :
            <strong class="text-white">{{ $fmt(array_sum(array_column($hourlyToday, 'visits'))) }}</strong> visites,
            <strong class="text-sky-400">{{ $fmt($kpis['today']['uniques']) }}</strong> uniques.
        </p>
    </section>

    {{-- ── Graphe quotidien (30 derniers jours) ──────────────────── --}}
    <section class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-4 flex items-center gap-2">
            <i class="fa-solid fa-chart-column"></i> Visites par jour — 30 derniers jours
        </h2>
        @php
            $nDays = count($dailyMonth);
            $barWD = $nDays > 0 ? 100 / $nDays : 0;
            $labelIndices = $nDays > 0
                ? array_values(array_unique([0, (int) ($nDays * 0.25), (int) ($nDays * 0.5), (int) ($nDays * 0.75), $nDays - 1]))
                : [];
        @endphp
        <div class="relative">
            <svg viewBox="0 0 100 {{ $chartH }}" preserveAspectRatio="none" class="w-full h-40 block">
                @foreach ([0.25, 0.5, 0.75] as $f)
                    <line x1="0" y1="{{ $chartH * (1 - $f) }}" x2="100" y2="{{ $chartH * (1 - $f) }}"
                          stroke="#1f2937" stroke-width="0.4" stroke-dasharray="0.8,0.8"/>
                @endforeach
                @foreach ($dailyMonth as $i => $row)
                    @php
                        $h = $chartH * ($row['visits'] / $maxDay);
                        $y = $chartH - $h;
                    @endphp
                    <rect x="{{ $i * $barWD + 0.2 }}" y="{{ $y }}"
                          width="{{ $barWD - 0.4 }}" height="{{ $h }}"
                          fill="{{ $row['visits'] > 0 ? '#0ea5e9' : '#374151' }}"
                          opacity="0.85">
                        <title>{{ $row['label'] }} : {{ $fmt($row['visits']) }} visites · {{ $fmt($row['uniques']) }} uniques</title>
                    </rect>
                @endforeach
            </svg>
            <div class="relative h-5 mt-1 text-[11px] text-gray-500 font-mono select-none">
                @foreach ($labelIndices as $i)
                    @if (isset($dailyMonth[$i]))
                        @php $left = $i * $barWD + $barWD / 2; @endphp
                        <span class="absolute -translate-x-1/2 top-0" style="left: {{ $left }}%;">{{ $dailyMonth[$i]['label'] }}</span>
                    @endif
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── 3 colonnes : Top pages / Appareils / OS+Navigateur ──── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

        {{-- Top pages --}}
        <section class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-3 flex items-center gap-2">
                <i class="fa-solid fa-file-lines"></i> Top pages (30 j)
            </h2>
            @if (empty($topPages))
                <p class="text-sm text-gray-500">Aucune visite enregistrée.</p>
            @else
                <ul class="divide-y divide-gray-800 -mx-2">
                    @foreach ($topPages as $p)
                        <li class="flex items-baseline justify-between gap-2 px-2 py-2">
                            <code class="text-xs text-gray-300 truncate" title="{{ $p['path'] }}">{{ $p['path'] }}</code>
                            <span class="text-sm font-medium text-white whitespace-nowrap">{{ $fmt($p['visits']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Appareils --}}
        <section class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-3 flex items-center gap-2">
                <i class="fa-solid fa-mobile-screen-button"></i> Type d'appareil (30 j)
            </h2>
            @if (empty($devices))
                <p class="text-sm text-gray-500">Aucune visite enregistrée.</p>
            @else
                <div class="space-y-2.5">
                    @foreach ($devices as $d)
                        @php
                            [$lbl, $ico, $textCls, $barCls] = $deviceLabel[$d['key']] ?? [$d['key'], 'fa-circle', 'text-gray-400', 'bg-gray-500'];
                            $pct = (int) round($d['visits'] / $totalDevices * 100);
                        @endphp
                        <div>
                            <div class="flex items-baseline justify-between text-xs mb-1">
                                <span class="text-gray-300">
                                    <i class="fa-solid {{ $ico }} {{ $textCls }} mr-1"></i>
                                    {{ $lbl }}
                                </span>
                                <span class="text-gray-400 font-mono">{{ $fmt($d['visits']) }} <span class="text-gray-600">({{ $pct }}%)</span></span>
                            </div>
                            <div class="h-1.5 bg-gray-800 rounded overflow-hidden">
                                <div class="h-full {{ $barCls }} rounded" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- OS + Navigateurs --}}
        <section class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-3 flex items-center gap-2">
                <i class="fa-solid fa-globe"></i> OS &amp; navigateur (30 j)
            </h2>
            <div class="mb-4">
                <div class="text-[11px] uppercase tracking-wider text-gray-500 mb-2">Système</div>
                @if (empty($os))
                    <p class="text-xs text-gray-500">—</p>
                @else
                    @foreach ($os as $row)
                        @php $pct = (int) round($row['visits'] / $totalOs * 100); @endphp
                        <div class="flex items-baseline justify-between text-xs py-1">
                            <span class="text-gray-300">{{ $row['key'] }}</span>
                            <span class="text-gray-500 font-mono">{{ $pct }}%</span>
                        </div>
                    @endforeach
                @endif
            </div>
            <div>
                <div class="text-[11px] uppercase tracking-wider text-gray-500 mb-2">Navigateur</div>
                @if (empty($browsers))
                    <p class="text-xs text-gray-500">—</p>
                @else
                    @foreach ($browsers as $row)
                        @php $pct = (int) round($row['visits'] / $totalBrowsers * 100); @endphp
                        <div class="flex items-baseline justify-between text-xs py-1">
                            <span class="text-gray-300">{{ $row['key'] }}</span>
                            <span class="text-gray-500 font-mono">{{ $pct }}%</span>
                        </div>
                    @endforeach
                @endif
            </div>
        </section>
    </div>

    {{-- ── Sites référents ──────────────────────────────────────── --}}
    <section class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-3 flex items-center gap-2">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Sites référents (30 j)
        </h2>
        @if (empty($referers))
            <p class="text-sm text-gray-500">
                Aucun référent externe sur la période. C'est attendu si les visiteurs
                arrivent en tapant l'URL directement ou via leurs favoris.
            </p>
        @else
            <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 divide-y divide-gray-800 sm:divide-y-0">
                @foreach ($referers as $r)
                    <li class="flex items-baseline justify-between gap-2 py-2">
                        <code class="text-xs text-gray-300 truncate" title="{{ $r['host'] }}">{{ $r['host'] }}</code>
                        <span class="text-sm font-medium text-white whitespace-nowrap">{{ $fmt($r['visits']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
