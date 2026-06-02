@extends('layouts.admin')
@section('title', 'Paramètres — Analytics')

@section('content')
<div>
    <x-admin.page-title
        title="Paramètres — Analytics"
        subtitle="Configuration du suivi de trafic et analytics." />

    @include('admin.settings._groups-form', [
        'settingsGroups' => $groups,
        'saveAction'     => route('admin.settings.update'),
    ])
</div>
@endsection
