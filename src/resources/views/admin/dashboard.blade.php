@extends('layouts.admin')
@section('title', 'Dashboard')

@section('content')
    <div>
        <x-admin.page-title title="Dashboard" :subtitle="'Bienvenue ' . auth()->user()->name . '.'" />

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Sites de vol</div>
                <div class="text-3xl font-mono text-white">{{ number_format($sitesTotal, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-1">
                    <span class="text-emerald-400 font-medium">{{ $sitesActive }}</span> actif{{ $sitesActive > 1 ? 's' : '' }}
                </div>
            </div>

            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Balises météo</div>
                <div class="text-3xl font-mono text-white">{{ number_format($balisesTotal, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-1">
                    <span class="text-emerald-400 font-medium">{{ $balisesActive }}</span> active{{ $balisesActive > 1 ? 's' : '' }}
                </div>
            </div>

            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Utilisateurs</div>
                <div class="text-3xl font-mono text-white">{{ number_format($usersTotal, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-1">total</div>
            </div>
        </div>

        @if (! empty($sitesByOrigin))
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
                <h2 class="text-xs uppercase tracking-wider text-gray-500 mb-3">Sites par origine</h2>
                <ul class="text-sm divide-y divide-gray-800">
                    @foreach ($sitesByOrigin as $source => $n)
                        <li class="flex justify-between py-2">
                            <span class="text-gray-300">{{ $source }}</span>
                            <span class="font-mono text-gray-400">{{ number_format($n, 0, ',', ' ') }}</span>
                        </li>
                    @endforeach
                </ul>
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
