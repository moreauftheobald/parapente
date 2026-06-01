@extends('layouts.admin')
@section('title', 'Paramètres — Modèles Météo')

@section('content')
<div>
    <x-admin.page-title title="Paramètres — Modèles Météo">
        <x-slot:subtitle>Configuration des modèles météo, APIs et paramètres de scoring.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', ['tab' => $tab, 'baseRoute' => 'admin.meteo.settings'])

    @if ($tab === 'general')
        <x-admin.empty-state icon="fa-solid fa-sliders" message="Les paramètres de scoring seront redistribués ici prochainement." />

    @elseif ($tab === 'data')
        <x-admin.empty-state icon="fa-solid fa-cloud-arrow-down" message="Les données météo sont récupérées automatiquement par le scheduler." />

    @elseif ($tab === 'logs')
        <x-admin.empty-state icon="fa-solid fa-scroll" message="Logs et monitoring météo — à venir." />
    @endif
</div>
@endsection
