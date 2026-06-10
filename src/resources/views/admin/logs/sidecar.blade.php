@extends('layouts.admin')
@section('title', 'Logs — Runs sidecar')

@section('content')
<div class="max-w-6xl">
    <x-admin.page-title title="Logs — runs du sidecar"
        subtitle="Historique des runs consensus du sidecar consensus-grid-v2 (via son API /runs)." />

    @include('admin.logs._tabs', ['active' => 'sidecar'])

    @include('admin.logs._sidecar-panel')
</div>
@endsection
