@extends('layouts.app')
@section('title', 'Carte météo parapente')

@push('styles')
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        @include('map._partials.styles.base')
        @include('map._partials.styles.left-panel')
        @include('map._partials.styles.toolbar')
        @include('map._partials.styles.panel')
        @include('map._partials.styles.charts')
        @include('map._partials.styles.tooltip')
    </style>
@endpush


@section('content')
    <div id="pg-app" x-data="mapApp()" x-init="init()"
         @click.window="dayDropOpen=false; bmDropOpen=false;">

        {{-- ═══ VOLET GAUCHE (filtres) · CARTE · VOLET DROIT (détail) ═══ --}}
        <div id="map-wrap">

            @include('map._partials.html.left-panel')

            <div id="map-area">
                <div id="map"></div>

                @include('map._partials.html.day-selector')
            </div>

            @include('map._partials.html.right-panel')

        </div>

        @include('map._partials.html.tooltip')

        @include('map._partials.html.dropdowns')

    </div>
@endsection

@push('styles')
    <script>
        @include('map._partials.scripts.config')
        @include('map._partials.scripts.icon')
        @include('map._partials.scripts.geometry')
        @include('map._partials.scripts.chart-line')
        @include('map._partials.scripts.chart-bar')
        @include('map._partials.scripts.popup-chart')
        @include('map._partials.scripts.tooltip')
        @include('map._partials.scripts.balise-icon')
        @include('map._partials.scripts.balise-chart')
        @include('map._partials.scripts.app')
    </script>
@endpush
