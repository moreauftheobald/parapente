@extends('layouts.admin')
@section('title', 'Supervision')

@php
    /** Helpers d'affichage locaux (pure présentation). */
    $stateBadge = fn (string $s) => match ($s) {
        'ok'     => 'success',
        'late'   => 'warning',
        'failed' => 'danger',
        default  => 'neutral',
    };
    $stateLabel = fn (string $s) => match ($s) {
        'ok'     => 'OK',
        'late'   => 'En retard',
        'failed' => 'Échec',
        default  => 'Inconnu',
    };
    $pctColor = fn (?int $p) => $p === null ? 'text-gray-500'
        : ($p >= 90 ? 'text-emerald-400' : ($p >= 60 ? 'text-amber-300' : 'text-red-300'));
    $nf = fn ($n) => number_format((int) $n, 0, ',', ' ');
@endphp

@section('content')
    <div>
        <x-admin.page-title title="Supervision"
            subtitle="Santé du pipeline météo, du sidecar et des données — en un coup d'œil." />

        {{-- ── Rangée 1 : sidecar · pipeline · incidents ─────────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">

            {{-- Sidecar scoring --}}
            <div class="bg-gray-900 border {{ ($sidecar['stale'] ?? false) ? 'border-red-500/50' : 'border-gray-800' }} rounded-xl p-5">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs uppercase tracking-wider text-gray-500">Scoring sidecar</div>
                    @if ($sidecar['stale'] ?? false)
                        <x-admin.badge status="danger">Périmé</x-admin.badge>
                    @else
                        <x-admin.badge status="success">À jour</x-admin.badge>
                    @endif
                </div>
                <div class="text-3xl font-mono text-white">
                    {{ ($sidecar['age_minutes'] ?? null) !== null ? $sidecar['age_minutes'] . ' min' : '—' }}
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    depuis le dernier run
                    @if (isset($sidecar['threshold_minutes']))
                        · seuil {{ $sidecar['threshold_minutes'] }} min
                    @endif
                </div>
            </div>

            {{-- Pipeline jobs --}}
            <div class="bg-gray-900 border {{ $jobs_summary['failed'] > 0 ? 'border-red-500/50' : ($jobs_summary['late'] > 0 ? 'border-amber-500/40' : 'border-gray-800') }} rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Pipeline ({{ count($jobs) }} jobs)</div>
                <div class="flex items-baseline gap-4 text-2xl font-mono">
                    <span class="text-emerald-400">{{ $jobs_summary['ok'] }} <span class="text-xs text-gray-500">ok</span></span>
                    <span class="{{ $jobs_summary['late'] > 0 ? 'text-amber-300' : 'text-gray-600' }}">{{ $jobs_summary['late'] }} <span class="text-xs text-gray-500">retard</span></span>
                    <span class="{{ $jobs_summary['failed'] > 0 ? 'text-red-300' : 'text-gray-600' }}">{{ $jobs_summary['failed'] }} <span class="text-xs text-gray-500">échec</span></span>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    {{ $jobs_summary['unknown'] }} sans trace (30 j) ·
                    <a href="{{ route('admin.logs.jobs') }}" class="text-sky-400 hover:underline">historique des jobs</a>
                </div>
            </div>

            {{-- Incidents 24 h --}}
            <div class="bg-gray-900 border {{ $failures->isNotEmpty() ? 'border-amber-500/40' : 'border-gray-800' }} rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Incidents (24 h)</div>
                <div class="text-3xl font-mono {{ $failures->isNotEmpty() ? 'text-amber-300' : 'text-white' }}">{{ $failures->count() }}</div>
                <div class="text-xs text-gray-500 mt-1">jobs en échec — détail en bas de page</div>
            </div>
        </div>

        {{-- ── Couverture (résumé) ───────────────────────────────────── --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            @foreach ([
                'sites_scored'      => route('admin.sites.settings'),
                'models_fetched'    => route('admin.meteo.settings'),
                'balises_emitting'  => route('admin.balises.settings'),
                'stations_emitting' => route('admin.weather-stations.settings'),
            ] as $key => $link)
                @php $c = $coverage[$key]; @endphp
                <a href="{{ $link }}" class="bg-gray-900 border border-gray-800 rounded-xl p-4 hover:border-gray-600 transition">
                    <div class="text-2xl font-mono {{ $pctColor($c['pct']) }}">
                        {{ $c['pct'] !== null ? $c['pct'] . ' %' : '—' }}
                    </div>
                    <div class="text-xs text-gray-400 mt-1">{{ $c['label'] }}</div>
                    <div class="text-[11px] text-gray-600">{{ $nf($c['n']) }} / {{ $nf($c['total']) }}</div>
                </a>
            @endforeach
        </div>

        {{-- ── Tableau pipeline ──────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-medium text-gray-300">
                <i class="fa-solid fa-gears mr-2 text-gray-500"></i>Jobs schedulés
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-800">
                        <th class="px-5 py-2">État</th>
                        <th class="px-3 py-2">Job</th>
                        <th class="px-3 py-2 hidden md:table-cell">Groupe</th>
                        <th class="px-3 py-2">Cadence</th>
                        <th class="px-3 py-2">Dernier succès</th>
                        <th class="px-3 py-2 hidden lg:table-cell">Durée</th>
                        <th class="px-3 py-2 hidden xl:table-cell">Message</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/60">
                    @foreach ($jobs as $job)
                        <tr class="{{ $job['state'] === 'failed' ? 'bg-red-500/5' : ($job['state'] === 'late' ? 'bg-amber-500/5' : '') }}">
                            <td class="px-5 py-2"><x-admin.badge :status="$stateBadge($job['state'])">{{ $stateLabel($job['state']) }}</x-admin.badge></td>
                            <td class="px-3 py-2 text-gray-200">{{ $job['label'] }}
                                <span class="block text-[11px] text-gray-600 font-mono">{{ $job['class'] }}</span></td>
                            <td class="px-3 py-2 text-gray-400 hidden md:table-cell">{{ $job['group'] }}</td>
                            <td class="px-3 py-2 text-gray-400 font-mono text-xs">{{ $job['expected_minutes'] >= 60 ? ($job['expected_minutes'] / 60) . ' h' : $job['expected_minutes'] . ' min' }}</td>
                            <td class="px-3 py-2 text-gray-300">{{ $job['last_success_at']?->diffForHumans() ?? 'jamais' }}</td>
                            <td class="px-3 py-2 text-gray-500 font-mono text-xs hidden lg:table-cell">{{ $job['duration'] }}</td>
                            <td class="px-3 py-2 text-gray-500 text-xs hidden xl:table-cell max-w-[28rem] truncate" title="{{ $job['message'] }}">{{ $job['message'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ── APIs ──────────────────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
            <div class="px-5 py-3 border-b border-gray-800 text-sm font-medium text-gray-300 flex items-center justify-between">
                <span><i class="fa-solid fa-plug mr-2 text-gray-500"></i>APIs externes</span>
                <span class="text-xs">
                    <a href="{{ route('admin.apis.index') }}" class="text-sky-400 hover:underline">prévisions</a> ·
                    <a href="{{ route('admin.station-apis.index') }}" class="text-sky-400 hover:underline">stations</a>
                </span>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-800">
                        <th class="px-5 py-2">API</th>
                        <th class="px-3 py-2">Type</th>
                        <th class="px-3 py-2">État</th>
                        <th class="px-3 py-2">Requêtes aujourd'hui</th>
                        <th class="px-3 py-2 hidden lg:table-cell">Dernière erreur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/60">
                    @foreach ($apis as $api)
                        <tr>
                            <td class="px-5 py-2 text-gray-200">{{ $api['name'] }}</td>
                            <td class="px-3 py-2 text-gray-400">{{ $api['kind'] }}</td>
                            <td class="px-3 py-2">
                                @if (! $api['active'])
                                    <x-admin.badge status="neutral">Inactive</x-admin.badge>
                                @elseif ($api['last_error'])
                                    <x-admin.badge status="warning">Erreur</x-admin.badge>
                                @else
                                    <x-admin.badge status="success">OK</x-admin.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-400 font-mono text-xs">
                                {{ $nf($api['requests_today'] ?? 0) }}{{ $api['daily_quota'] ? ' / ' . $nf($api['daily_quota']) : '' }}
                            </td>
                            <td class="px-3 py-2 text-gray-500 text-xs hidden lg:table-cell max-w-[28rem] truncate" title="{{ $api['last_error'] }}">
                                @if ($api['last_error'])
                                    {{ $api['last_error_at']?->diffForHumans() }} — {{ $api['last_error'] }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ── Incidents récents ─────────────────────────────────────── --}}
        @if ($failures->isNotEmpty())
            <div class="bg-gray-900 border border-amber-500/30 rounded-xl overflow-hidden mb-6">
                <div class="px-5 py-3 border-b border-gray-800 text-sm font-medium text-amber-300">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i>Échecs des dernières 24 h
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-800/60">
                        @foreach ($failures as $f)
                            <tr>
                                <td class="px-5 py-2 text-gray-200 whitespace-nowrap">{{ $f->shortName() }}</td>
                                <td class="px-3 py-2 text-gray-400 whitespace-nowrap">{{ $f->started_at?->diffForHumans() }}</td>
                                <td class="px-3 py-2 text-gray-500 text-xs max-w-[36rem] truncate" title="{{ $f->error_message }}">{{ $f->error_message }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ── Volumétrie ────────────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
            <div class="text-xs uppercase tracking-wider text-gray-500 mb-3">
                <i class="fa-solid fa-database mr-2"></i>Volumétrie
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-x-6 gap-y-3 text-sm">
                @foreach ([
                    'Sites actifs'            => $nf($volumetry['sites_active']) . ' / ' . $nf($volumetry['sites_total']),
                    'Balises'                 => $nf($volumetry['balises_total']),
                    'Stations météo'          => $nf($volumetry['stations_total']),
                    'Utilisateurs'            => $nf($volumetry['users_total']),
                    'Lectures balises (7 j)'  => $nf($volumetry['balise_readings']),
                    'Horaire balises (30 j)'  => $nf($volumetry['balise_readings_hourly']),
                    'Obs. stations (7 j)'     => $nf($volumetry['station_observations']),
                    'Horaire stations (30 j)' => $nf($volumetry['station_observations_hourly']),
                    'Archive balises (30 j)'  => $nf($volumetry['forecast_archive_balises']),
                    'Archive stations (30 j)' => $nf($volumetry['forecast_archive_stations']),
                    'Prévisions sites (J-1)'  => $nf($volumetry['forecasts']),
                ] as $label => $value)
                    <div>
                        <div class="font-mono text-gray-200">{{ $value }}</div>
                        <div class="text-[11px] text-gray-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Rapport de déploiement géographique (post-action sync) ── --}}
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
                        <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Sites</div>
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
                                Balises {{ $sourceLabel[$key] ?? $key }}
                            </div>
                            <div class="text-2xl font-semibold text-white">{{ $b['newly_activated'] }}</div>
                            <div class="text-xs text-gray-400">
                                nouvellement activée{{ $b['newly_activated'] > 1 ? 's' : '' }}
                                ({{ $b['created'] }} créée{{ $b['created'] > 1 ? 's' : '' }})
                                · {{ $b['already_active'] }} déjà active{{ $b['already_active'] > 1 ? 's' : '' }}
                                @if ($b['kept_off'] > 0)
                                    · <span class="text-amber-400">{{ $b['kept_off'] }} laissée{{ $b['kept_off'] > 1 ? 's' : '' }} off</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <x-admin.section title="Déploiement géographique" icon="fa-solid fa-globe" color="sky">
            <p class="text-sm text-gray-500 mb-4">
                Géocode une ville, puis active <strong class="text-gray-400">tous les sites</strong>
                dans le rayon et <strong class="text-gray-400">découvre + active</strong>
                toutes les balises du périmètre. Cumulatif : rien n'est désactivé hors zone.
            </p>
            @if ($errors->any())
                <x-admin.alert type="error">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-admin.alert>
            @endif
            <form method="POST" action="{{ route('admin.sync.deploy') }}"
                  onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Déploiement en cours…';">
                @csrf
                <div class="flex items-center gap-4 flex-wrap">
                    <x-admin.input name="city" label="Ville" required
                                   :value="old('city')" placeholder="ex. Annecy, Chamonix, Millau…"
                                   hint="Nom de la ville à géocoder via Open-Meteo."
                                   wrapperClass="flex-1 min-w-[200px]" />
                    <x-admin.input name="radius_km" label="Rayon (km)" type="number"
                                   min="1" max="500" step="1" required
                                   :value="old('radius_km', 50)"
                                   hint="Rayon de recherche autour de la ville (1-500 km)."
                                   wrapperClass="w-48" />
                    <x-admin.button type="submit" variant="primary" icon="fa-solid fa-rocket" class="mt-0 self-center">
                        Lancer le déploiement
                    </x-admin.button>
                </div>
            </form>
        </x-admin.section>
    </div>
@endsection
