@extends('layouts.admin')
@section('title', 'Data / couverture')

@section('content')
<div>
    <x-admin.page-title title="Data / couverture">
        <x-slot:subtitle>Fraîcheur des modèles, couverture des prévisions et des observations, imports et découverte — par famille de données.</x-slot:subtitle>
    </x-admin.page-title>

    @include('admin._settings-tabs', [
        'tab'       => $tab,
        'baseRoute' => 'admin.data.index',
        'tabs'      => [
            'models'   => ['label' => 'Modèles',        'icon' => 'fa-solid fa-cloud'],
            'sites'    => ['label' => 'Sites',          'icon' => 'fa-solid fa-mountain-sun'],
            'balises'  => ['label' => 'Balises',        'icon' => 'fa-solid fa-tower-broadcast'],
            'stations' => ['label' => 'Stations météo', 'icon' => 'fa-solid fa-tower-observation'],
        ],
    ])

    @if ($tab === 'models')
        @include('admin.data._tab-models')
    @elseif ($tab === 'sites')
        @include('admin.data._tab-sites')
    @elseif ($tab === 'balises')
        @include('admin.data._tab-balises')
    @elseif ($tab === 'stations')
        @include('admin.data._tab-stations')
    @endif
</div>
@endsection
