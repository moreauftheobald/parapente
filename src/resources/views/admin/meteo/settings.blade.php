@extends('layouts.admin')
@section('title', 'Consensus & sidecar')

@section('content')
<div>
    <x-admin.page-title title="Consensus & sidecar">
        <x-slot:subtitle>Configuration du consensus par variable, overrides de variables, dépendances et état du sidecar.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', ['tab' => $tab, 'baseRoute' => 'admin.meteo.settings', 'tabs' => $tabs])

    @if ($tab === 'consensus')
        @include('admin.meteo._tab-consensus')

    @elseif ($tab === 'variables')
        @include('admin.meteo._tab-variables')

    @elseif ($tab === 'dependencies')
        @include('admin.meteo._tab-dependencies')

    @elseif ($tab === 'sidecar')
        @include('admin.meteo._tab-sidecar')
    @endif
</div>
@endsection
