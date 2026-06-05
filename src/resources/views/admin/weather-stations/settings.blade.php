@extends('layouts.admin')
@section('title', 'Paramètres — Stations météo')

@section('content')
<div>
    <x-admin.page-title title="Paramètres — Stations météo">
        <x-slot:subtitle>Configuration des réseaux, clés API et monitoring des stations météo.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', ['tab' => $tab, 'baseRoute' => 'admin.weather-stations.settings'])

    @if ($tab === 'general')
        @include('admin.settings._groups-form', [
            'settingsGroups' => $settingsGroups,
            'saveAction'     => route('admin.weather-stations.settings.general'),
        ])

        <div class="mt-8 space-y-4">
            <h2 class="text-xs uppercase tracking-wider text-gray-400">
                <i class="fa-solid fa-circle-info text-sky-400"></i> Réseaux disponibles
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ([
                    ['key' => 'mf',         'label' => 'Météo-France',  'color' => '#3b82f6', 'icon' => 'fa-cloud-sun',          'auth' => 'OAuth2 (portail MF)',           'configured' => $mfKeyConfigured, 'desc' => 'Stations synoptiques et climatologiques + Nivose haute altitude. ~1800 stations en France métropolitaine. Observations horaires (vent, température, pression, humidité, précipitations).'],
                    ['key' => 'metar',      'label' => 'METAR',         'color' => '#7c3aed', 'icon' => 'fa-plane',               'auth' => 'Aucune',                       'configured' => true,             'desc' => 'Observations aéronautiques (NOAA). ~115 aérodromes en France. Vent, température, pression, visibilité.'],
                    ['key' => 'infoclimat', 'label' => 'Infoclimat',    'color' => '#16a34a', 'icon' => 'fa-temperature-half',    'auth' => 'API key (infoclimat.fr)',       'configured' => $icKeyConfigured, 'desc' => 'Réseau de stations amateurs. ~1000+ stations en France. Couverture rurale dense. Qualité variable (filtrage nécessaire).'],
                ] as $net)
                    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
                        <div class="flex items-center gap-3 mb-2">
                            <i class="fa-solid {{ $net['icon'] }} w-5 text-center" style="color:{{ $net['color'] }}"></i>
                            <span class="text-sm font-medium text-white">{{ $net['label'] }}</span>
                            @if ($net['configured'])
                                <span class="ml-auto px-2 py-0.5 text-[10px] rounded border border-emerald-500/30 bg-emerald-500/10 text-emerald-300">
                                    <i class="fa-solid fa-circle-check"></i> Prêt
                                </span>
                            @else
                                <span class="ml-auto px-2 py-0.5 text-[10px] rounded border border-amber-500/30 bg-amber-500/10 text-amber-300">
                                    <i class="fa-solid fa-key"></i> Clé requise
                                </span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 leading-relaxed">{{ $net['desc'] }}</p>
                        <div class="mt-2 text-[11px] text-gray-600">
                            <span class="text-gray-500">Auth :</span> {{ $net['auth'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

    @elseif ($tab === 'data')

        @if (session('sync_output'))
            <div class="mb-6 bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
                <div class="px-4 py-2 text-xs uppercase tracking-wider text-gray-500 border-b border-gray-800">
                    Résultat de la dernière opération
                </div>
                <pre class="px-4 py-3 text-xs text-gray-300 whitespace-pre-wrap font-mono leading-relaxed">{{ session('sync_output') }}</pre>
            </div>
        @endif

        @if ($errors->any())
            <x-admin.alert type="error">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-admin.alert>
        @endif

        <div class="space-y-6 mb-8">
            @foreach ([
                [
                    'network' => 'mf',
                    'title'   => 'Découverte — Météo-France',
                    'icon'    => 'fa-solid fa-cloud-sun',
                    'color'   => '#3b82f6',
                    'desc'    => 'Stations synoptiques, climatologiques et Nivose. Découverte via /liste-stations (OAuth2 Bearer).',
                    'auth'    => 'oauth2',
                ],
                [
                    'network' => 'metar',
                    'title'   => 'Découverte — METAR (NOAA)',
                    'icon'    => 'fa-solid fa-plane',
                    'color'   => '#7c3aed',
                    'desc'    => 'Stations aéroportuaires via Aviation Weather Center. Aucune clé requise.',
                    'auth'    => 'none',
                ],
                [
                    'network' => 'infoclimat',
                    'title'   => 'Découverte — Infoclimat (StatIC)',
                    'icon'    => 'fa-solid fa-temperature-half',
                    'color'   => '#16a34a',
                    'desc'    => 'Stations amateurs du réseau StatIC. Filtre les stations actives < 6 mois. Aucune clé requise pour la découverte.',
                    'auth'    => 'none',
                ],
            ] as $src)
                @php
                    $api = $stationApis->get($src['network']);
                    $count = $stationCounts[$src['network']] ?? 0;
                    $credentialsOk = match ($src['auth']) {
                        'oauth2' => $stationApis->get($src['network'])?->hasOAuth2Credentials() ?? false,
                        'api_key' => ! empty($api?->api_key),
                        default => true,
                    };
                    $apiActive = $api?->active ?? false;
                @endphp
                <x-admin.section :title="$src['title']" icon="{{ $src['icon'] }}" color="sky">
                    <div class="flex items-center gap-3 mb-3">
                        <i class="fa-solid fa-tower-broadcast" style="color:{{ $src['color'] }}"></i>
                        <span class="text-xs text-gray-500">
                            {{ number_format($count, 0, ',', ' ') }} station{{ $count > 1 ? 's' : '' }} en base
                        </span>
                        @if ($apiActive)
                            <span class="px-2 py-0.5 text-[10px] rounded border border-emerald-500/30 bg-emerald-500/10 text-emerald-300">
                                <i class="fa-solid fa-circle-check"></i> API active
                            </span>
                        @else
                            <span class="px-2 py-0.5 text-[10px] rounded border border-gray-600 bg-gray-800 text-gray-400">
                                <i class="fa-solid fa-circle-xmark"></i> API inactive
                            </span>
                        @endif
                    </div>

                    @if (! $credentialsOk)
                        <x-admin.alert type="warning">
                            Credentials non configurés.
                            <a href="{{ route('admin.station-apis.index') }}" class="underline hover:text-amber-200">Configurer dans APIs stations</a>.
                        </x-admin.alert>
                    @endif

                    <form method="POST" action="{{ route('admin.sync.weather-stations') }}"
                          onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Découverte en cours…';">
                        @csrf
                        <input type="hidden" name="network" value="{{ $src['network'] }}">
                        <div class="flex items-center gap-4 flex-wrap">
                            <x-admin.input name="lat_min" label="Lat min" type="number" step="0.01"
                                           :value="old('lat_min', $bbox['lat_min'])" wrapperClass="w-36" />
                            <x-admin.input name="lat_max" label="Lat max" type="number" step="0.01"
                                           :value="old('lat_max', $bbox['lat_max'])" wrapperClass="w-36" />
                            <x-admin.input name="lng_min" label="Lng min" type="number" step="0.01"
                                           :value="old('lng_min', $bbox['lng_min'])" wrapperClass="w-36" />
                            <x-admin.input name="lng_max" label="Lng max" type="number" step="0.01"
                                           :value="old('lng_max', $bbox['lng_max'])" wrapperClass="w-36" />
                            <x-admin.button type="submit" variant="primary" icon="fa-solid fa-tower-broadcast"
                                            class="self-center" :disabled="! $credentialsOk">
                                Découvrir
                            </x-admin.button>
                        </div>
                        <p class="text-xs text-gray-600 mt-2">{{ $src['desc'] }}</p>
                    </form>
                </x-admin.section>
            @endforeach
        </div>

        {{-- Couverture prévisions stations ────────────────────────────── --}}
        @php require resource_path('views/admin/_coverage-helpers.php'); @endphp

        <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
            <i class="fa-solid fa-box-archive text-violet-400"></i>
            Prévisions stations — J-7 → J+5
            <span class="text-gray-600 normal-case">({{ $stationForecasts['stations_active'] }} stations actives, horizon archivé limité à J+2)</span>
        </h2>

        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-8">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                    <tr>
                        <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Modèle</th>
                        @foreach ($stationForecasts['days'] as $day)
                            <th class="text-center px-2 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                                <div class="text-white text-[11px]">{{ $fmtDay($day, $today) }}</div>
                                <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D/MM') }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/70">
                    @forelse ($stationForecasts['rows'] as $row)
                        <tr @class(['opacity-60' => ! $row['archived']])>
                            <td class="px-3 py-2 sticky left-0 bg-gray-900 z-10">
                                <div class="text-white">
                                    {{ $row['model']->name }}
                                    @unless ($row['archived'])
                                        <span class="ml-1 text-[10px] uppercase tracking-wider text-gray-500"
                                              title="Modèle d'horizon < 24h — exclu de l'archive stations par FetchStationForecastsJob">non archivé</span>
                                    @endunless
                                </div>
                                <div class="text-xs text-gray-500 font-mono">
                                    {{ $row['model']->code }}
                                    <span class="ml-1 text-gray-600">· {{ $row['model']->max_horizon_h }}h</span>
                                </div>
                            </td>
                            @foreach ($row['cells'] as $cell)
                                <td class="px-1 py-2 text-center">
                                    <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($cell) }}"
                                          title="{{ $cellTooltip($cell) }}">
                                        {{ $fmtCell($cell) }}
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($stationForecasts['days']) + 1 }}" class="px-3 py-6 text-center text-gray-500">Aucun modèle actif ou aucune station active.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Couverture relevés stations ──────────────────────────────── --}}
        <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
            <i class="fa-solid fa-tower-broadcast text-emerald-400"></i>
            Relevés stations — J-7 → J
            <span class="text-gray-600 normal-case">(observations de <code>weather_station_observations</code>)</span>
        </h2>

        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-4">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                    <tr>
                        <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Réseau / Station</th>
                        @foreach ($stationReadings['days'] as $day)
                            <th class="text-center px-2 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                                <div class="text-white text-[11px]">{{ $fmtDay($day, $today) }}</div>
                                <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D/MM') }}</div>
                            </th>
                        @endforeach
                        <th class="text-right px-3 py-2 font-medium">Dernière réception</th>
                    </tr>
                </thead>
                @forelse ($stationReadings['groups'] as $group)
                    <tbody class="divide-y divide-gray-800/70 border-t border-gray-800/70"
                           x-data="{ open: false }">
                        <tr class="bg-gray-800/40 hover:bg-gray-800/70 transition cursor-pointer select-none"
                            @click="open = !open">
                            <td class="px-3 py-2 sticky left-0 bg-gray-800/40 z-10">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-chevron-right w-3 text-gray-400 transition-transform"
                                       :class="open && 'rotate-90'"></i>
                                    <span class="text-white font-medium">{{ $group['label'] }}</span>
                                    <span class="text-xs text-gray-500">({{ $group['stations_count'] }} station{{ $group['stations_count'] > 1 ? 's' : '' }})</span>
                                </div>
                            </td>
                            @foreach ($group['aggregate_cells'] as $pct)
                                <td class="px-1 py-2 text-center">
                                    <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($pct) }}"
                                          title="{{ $pct === null ? 'N/A' : 'Agrégat réseau : '.$pct.'%' }}">
                                        {{ $fmtPct($pct) }}
                                    </span>
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-right text-xs text-gray-500" x-show="!open">
                                <span class="text-gray-500">détail&hellip;</span>
                            </td>
                            <td class="px-3 py-2 text-right text-xs text-gray-500" x-show="open" x-cloak>
                                <span class="text-gray-500">réduire</span>
                            </td>
                        </tr>

                        @foreach ($group['stations'] as $row)
                            <tr x-show="open" x-cloak class="hover:bg-gray-800/30 transition">
                                <td class="px-3 py-1.5 sticky left-0 bg-gray-900 z-10 pl-8">
                                    <div class="text-white text-[13px]">{{ $row['station']->name }}</div>
                                    <div class="text-[10px] text-gray-500 font-mono">#{{ $row['station']->id }}</div>
                                </td>
                                @foreach ($row['cells'] as $pct)
                                    <td class="px-1 py-1.5 text-center">
                                        <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($pct) }}"
                                              title="{{ $pct === null ? 'N/A' : $pct.'%' }}">
                                            {{ $fmtPct($pct) }}
                                        </span>
                                    </td>
                                @endforeach
                                <td class="px-3 py-1.5 text-right font-mono text-[11px] text-gray-300">{{ $fmtDateTime($row['last_reading_at']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="{{ count($stationReadings['days']) + 2 }}" class="px-3 py-6 text-center text-gray-500">Aucune station active.</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        @include('admin._coverage-legend')

        @push('styles')
            <style>[x-cloak] { display: none !important; }</style>
        @endpush

    @elseif ($tab === 'logs')
        @include('admin._monitor-tab')
    @endif
</div>
@endsection
