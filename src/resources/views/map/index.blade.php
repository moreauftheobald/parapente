<x-app-shell
    title="Carte météo parapente"
    page-title="Carte météo"
    x-data="mapApp()"
    right-class="w-full max-w-full lg:w-[clamp(420px,45vw,640px)] lg:max-w-[60vw] xl:w-[50vw] xl:max-w-[50vw]"
    main-class="overflow-hidden"
    root-class="map-shell"
    :hide-detail-header="true"
    :hide-help-header="true"
    detail-body-class="flex-1 min-h-0 overflow-hidden"
    help-body-class="flex-1 min-h-0 overflow-hidden">

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

    {{-- ═══ VOLET GAUCHE (filtres, légende, sélecteur de jour sur mobile) ═══ --}}
    <x-slot:detail>
        @include('map._partials.html.left-panel')
    </x-slot:detail>

    {{-- ═══ VOLET DROIT (détail site / balise) ═══ --}}
    <x-slot:help>
        @include('map._partials.html.right-panel')
    </x-slot:help>

    {{-- ═══ CONTENU PRINCIPAL : la carte Leaflet ═══ --}}
    {{-- init() de mapApp() est appelé automatiquement par Alpine.
         Le sélecteur de jour n'est plus flottant sur la carte ; il
         vit désormais en tête fixe du volet gauche (cf. left-panel). --}}
    <div id="map-area" @click.window="dayDropOpen=false; bmDropOpen=false;">
        <div id="map"></div>
    </div>

    {{-- Tooltips & dropdowns flottants (échappent au flux normal via position:fixed) --}}
    @include('map._partials.html.tooltip')
    @include('map._partials.html.dropdowns')

    @push('scripts')
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
</x-app-shell>
