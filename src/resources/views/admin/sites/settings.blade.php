@extends('layouts.admin')
@section('title', 'Paramètres — Sites')

@php
    $selectCls = 'w-full px-2.5 py-1.5 text-sm bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40 cursor-pointer';
@endphp

@section('content')
<div>
    <x-admin.page-title title="Paramètres — Sites">
        <x-slot:subtitle>Configuration, import et monitoring des sites de vol.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', ['tab' => $tab, 'baseRoute' => 'admin.sites.settings'])

    @if ($tab === 'general')
        <x-admin.empty-state icon="fa-solid fa-sliders" message="Les paramètres des sites seront redistribués ici prochainement." />

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

        <x-admin.section title="Import — ParaglidingEarth" icon="fa-solid fa-cloud-arrow-down" color="sky">
            <span class="text-xs text-gray-500 mb-3 block">
                {{ number_format($sitesPge, 0, ',', ' ') }} importé{{ $sitesPge > 1 ? 's' : '' }}
                · {{ number_format($sitesTotal, 0, ',', ' ') }} au total
            </span>
            <form method="POST" action="{{ route('admin.sync.sites') }}"
                  onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Import en cours…';">
                @csrf
                <div class="flex items-center gap-4 flex-wrap">
                    <x-admin.field name="iso" label="Pays"
                                   hint="Code ISO du pays à importer depuis ParaglidingEarth. Les nouveaux sites sont créés inactifs.">
                        <select name="iso" id="iso" class="{{ $selectCls }}">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}" @selected(old('iso', 'fr') === $code)>{{ $name }} ({{ strtoupper($code) }})</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.input name="limit" label="Limite" type="number"
                                   min="1" max="20000" placeholder="toutes"
                                   :value="old('limit')"
                                   hint="Nombre max de sites à importer (vide = tous)."
                                   wrapperClass="w-40" />
                    <x-admin.button type="submit" variant="primary" icon="fa-solid fa-cloud-arrow-down" class="self-center">
                        Importer
                    </x-admin.button>
                </div>
            </form>
        </x-admin.section>

    @elseif ($tab === 'logs')
        <x-admin.empty-state icon="fa-solid fa-scroll" message="Logs et monitoring des sites — à venir." />
    @endif
</div>
@endsection
