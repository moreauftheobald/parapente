<x-app-shell
    title="Carte de volabilité parapente"
    page-title="Carte de volabilité"
    x-data="mapApp()"
    right-class="w-full max-w-full lg:w-[500px] lg:max-w-[500px]"
    main-class="overflow-hidden"
    root-class="map-shell"
    :hide-detail-header="true"
    :hide-help-header="true"
    detail-body-class="flex-1 min-h-0 overflow-hidden"
    help-body-class="flex-1 min-h-0 overflow-hidden">

    @push('styles')
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
        {{-- Tabler Icons (webfont) — utilisé par le panneau droit v2. CDN pour
             l'instant ; un self-host est possible plus tard (cf. plan refonte). --}}
        <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3/dist/tabler-icons.min.css" rel="stylesheet">
        <style>
            @include('map._partials.styles.base')
            @include('map._partials.styles.left-panel')
            @include('map._partials.styles.toolbar')
            @include('map._partials.styles.panel')
            @include('map._partials.styles.charts')
            @include('map._partials.styles.tooltip')
            @include('map._partials.styles.right-panel-v2')
            [x-cloak]{ display:none !important; }
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

        {{-- Bandeau « scoring périmé » : visible quand le sidecar a cessé de
             produire des runs (cf. ScoringFreshness / WatchScoringTableJob). --}}
        <div x-cloak x-show="scoringStale && scoringStale.stale"
             style="position:fixed;top:64px;left:50%;transform:translateX(-50%);z-index:75;
                    max-width:92vw;display:flex;gap:8px;align-items:center;
                    background:#7c2d12;color:#fff;border:1px solid #ea580c;border-radius:8px;
                    padding:8px 14px;font:500 13px/1.35 var(--font-mono,monospace);
                    box-shadow:0 6px 18px rgba(0,0,0,.35);">
            <span style="font-size:15px;line-height:1;">⚠️</span>
            <span>Prévisions non rafraîchies depuis
                <strong x-text="scoringStale ? (scoringStale.age_minutes >= 120
                    ? (Math.round(scoringStale.age_minutes/6)/10 + ' h')
                    : (scoringStale.age_minutes + ' min')) : ''"></strong>
                — données potentiellement périmées.</span>
        </div>
    </div>

    {{-- Tooltips & dropdowns flottants (échappent au flux normal via position:fixed) --}}
    @include('map._partials.html.tooltip')
    @include('map._partials.html.dropdowns')

    @push('scripts')
        {{-- Chart.js — uniquement pour le Consensus Ribbon (onglet Modèles, Phase 3).
             Chargé avant le bloc inline pour que `Chart` soit défini à l'usage. --}}
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
            @include('map._partials.scripts.station-icon')
            @include('map._partials.scripts.station-chart')
            @include('map._partials.scripts.panel-synthese')
            @include('map._partials.scripts.panel-scoring')
            @include('map._partials.scripts.panel-ribbon')
            @include('map._partials.scripts.app')
        </script>
    @endpush
</x-app-shell>
