@extends('layouts.admin')
@section('title', 'Paramètres — Contenu')

@section('content')
<div>
    <x-admin.page-title title="Paramètres — Contenu">
        <x-slot:subtitle>Configuration des articles, du wiki et du contenu éditorial.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', ['tab' => $tab, 'baseRoute' => 'admin.contenu.settings'])

    @if ($tab === 'general')
        <x-admin.empty-state icon="fa-solid fa-sliders" message="Aucun paramètre configurable pour cette section pour le moment." />

    @elseif ($tab === 'data')
        <x-admin.empty-state icon="fa-solid fa-cloud-arrow-down" message="Aucune source de données externe pour le contenu éditorial." />

    @elseif ($tab === 'logs')
        <x-admin.empty-state icon="fa-solid fa-scroll" message="Logs et monitoring du contenu — à venir." />
    @endif
</div>
@endsection
