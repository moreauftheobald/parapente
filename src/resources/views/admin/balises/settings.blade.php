@extends('layouts.admin')
@section('title', 'Paramètres — Balises')

@section('content')
<div>
    <x-admin.page-title title="Paramètres — Balises">
        <x-slot:subtitle>Configuration, découverte et monitoring des balises météo.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', ['tab' => $tab, 'baseRoute' => 'admin.balises.settings'])

    @if ($tab === 'general')
        <x-admin.empty-state icon="fa-solid fa-sliders" message="Les paramètres des balises seront redistribués ici prochainement." />

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

        <div class="space-y-6">
            @foreach ([
                ['source' => 'pioupiou', 'title' => 'Découverte — PiouPiou',              'icon' => 'fa-solid fa-tower-broadcast', 'count' => $balisesPiou,  'desc' => 'Stations PiouPiou actives (dernières 24 h).'],
                ['source' => 'metar',    'title' => 'Découverte — METAR (NOAA)',           'icon' => 'fa-solid fa-plane',           'count' => $balisesMetar, 'desc' => 'Stations aéroportuaires (observations METAR).'],
                ['source' => 'windy',    'title' => 'Découverte — Windy.com (Open Data)',  'icon' => 'fa-solid fa-wind',            'count' => $balisesWindy, 'desc' => 'Stations Windy publiées sous licence ouverte.'],
            ] as $src)
                <x-admin.section :title="$src['title']" :icon="$src['icon']" color="sky">
                    <span class="text-xs text-gray-500 mb-3 block">{{ number_format($src['count'], 0, ',', ' ') }} en base</span>

                    @if ($src['source'] === 'windy' && ! $windyKeyConfigured)
                        <x-admin.alert type="warning">
                            Clé API Windy non configurée.
                            <a href="{{ route('admin.settings.index') }}" class="underline hover:text-amber-200">Paramètres système</a>.
                        </x-admin.alert>
                    @endif

                    <form method="POST" action="{{ route('admin.sync.balises') }}"
                          onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Découverte en cours…';">
                        @csrf
                        <input type="hidden" name="source" value="{{ $src['source'] }}">
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
                                            class="self-center" :disabled="$src['source'] === 'windy' && ! $windyKeyConfigured">
                                Découvrir
                            </x-admin.button>
                        </div>
                    </form>
                </x-admin.section>
            @endforeach
        </div>

    @elseif ($tab === 'logs')
        <x-admin.empty-state icon="fa-solid fa-scroll" message="Logs et monitoring des balises — à venir." />
    @endif
</div>
@endsection
