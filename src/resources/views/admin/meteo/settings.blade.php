@extends('layouts.admin')
@section('title', 'Paramètres — Modèles Météo')

@section('content')
<div>
    <x-admin.page-title title="Paramètres — Modèles Météo">
        <x-slot:subtitle>Configuration des modèles météo, consensus sidecar, orchestration et scoring.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', ['tab' => $tab, 'baseRoute' => 'admin.meteo.settings', 'tabs' => $tabs])

    @if ($tab === 'general')
        @include('admin.meteo._tab-general')

    @elseif ($tab === 'data')
        @include('admin.meteo._tab-data')

    @elseif ($tab === 'consensus')
        @include('admin.meteo._tab-consensus')

    @elseif ($tab === 'orchestration')
        @include('admin.meteo._tab-orchestration')

    @elseif ($tab === 'variables')
        @include('admin.meteo._tab-variables')

    @elseif ($tab === 'dependencies')
        @include('admin.meteo._tab-dependencies')

    @elseif ($tab === 'sidecar')
        @include('admin.meteo._tab-sidecar')

    @elseif ($tab === 'logs')
        @include('admin.meteo._tab-logs')
    @endif
</div>
@endsection
