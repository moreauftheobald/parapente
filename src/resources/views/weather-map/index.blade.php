<x-app-shell
    title="Carte météo"
    page-title="Carte météo"
    main-class="overflow-hidden">

    @push('styles')
    <style>
        /* Conteneur plein hauteur — même pattern que model-grid/index.blade.php :
           pas de position:absolute, flex column pour empiler toolbar + carte. */
        #wm-root {
            width: 100%; height: 100%;
            min-width: 0; min-height: 0;
            position: relative;
            display: flex; flex-direction: column;
            background: #0b1220;
            color: #cbd5e1;
            font-family: 'DM Sans', system-ui, sans-serif;
        }

        #wm-toolbar {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 12px; padding: 10px 14px;
            background: #0f172a;
            border-bottom: 1px solid #1e293b;
            z-index: 50;
            flex: 0 0 auto;
        }
        #wm-toolbar label.field {
            display: flex; flex-direction: column; gap: 2px;
            font-size: 10px; text-transform: uppercase;
            letter-spacing: 0.05em; color: #64748b;
        }
        #wm-toolbar select,
        #wm-toolbar input[type="range"],
        #wm-toolbar button {
            background: #1e293b; color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 6px;
            font-size: 12px; padding: 4px 8px;
            min-width: 90px;
        }
        #wm-toolbar select:focus,
        #wm-toolbar button:focus { outline: none; border-color: #0ea5e9; }
        #wm-toolbar button {
            cursor: pointer; min-width: 80px;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        }
        #wm-toolbar button:hover { background: #334155; }
        #wm-toolbar button.playing {
            background: #0c4a6e; border-color: #0ea5e9; color: #7dd3fc;
        }
        #wm-toolbar .chk {
            flex-direction: row; align-items: center; gap: 6px;
            color: #cbd5e1; cursor: pointer;
        }
        #wm-toolbar input[type="range"] {
            min-width: 240px; padding: 0;
        }
        #wm-toolbar input[type="range"]:disabled { opacity: .4; }

        #wm-status {
            margin-left: auto;
            display: flex; gap: 12px; align-items: center;
            font-family: 'DM Mono', monospace;
            font-size: 11px; color: #94a3b8;
        }
        #wm-status .pill {
            padding: 2px 8px; border-radius: 999px;
            background: #1e293b; border: 1px solid #334155;
        }
        #wm-status .pill.ok   { background: #052e16; border-color: #14532d; color: #4ade80; }
        #wm-status .pill.warn { background: #422006; border-color: #92400e; color: #fbbf24; }
        #wm-status .pill.err  { background: #450a0a; border-color: #7f1d1d; color: #fca5a5; }

        #wm-step-label {
            font-family: 'DM Mono', monospace;
            font-size: 11px; color: #cbd5e1;
            min-width: 130px; text-align: center;
        }
        #wm-step-label .h { color: #94a3b8; font-size: 10px; }

        #wm-map {
            flex: 1 1 auto;
            min-height: 0;
            position: relative;
            background: #020617;
        }

        /* Rendu pixelisé de l'overlay météo : Leaflet l'agrandit avec un
           filtrage bilinéaire par défaut, ce qui floute la vraie résolution
           ~2.77 km/pixel du modèle. On rend chaque cellule comme un bloc
           net (cf. la même approche que les tuiles raster MET / Windy). */
        .wm-overlay-img {
            image-rendering: pixelated;
            image-rendering: crisp-edges; /* fallback Firefox */
        }

        /* Légende — L.Control bottomleft */
        .wm-legend {
            background: rgba(15,23,42,0.95);
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 11px;
            color: #cbd5e1;
            box-shadow: 0 4px 16px rgba(0,0,0,.4);
            min-width: 220px; max-width: 280px;
            pointer-events: auto;
        }
        .wm-legend h4 {
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;
            color: #64748b; margin: 0 0 6px 0;
        }
        .wm-legend .scale {
            height: 12px; border-radius: 3px;
            border: 1px solid rgba(255,255,255,.1);
            background: linear-gradient(to right, #00ff00, #ffff00, #ff0000); /* dégradé fallback */
        }
        .wm-legend .axis {
            display: flex; justify-content: space-between;
            font-family: 'DM Mono', monospace; font-size: 10px;
            color: #94a3b8; margin-top: 4px;
        }
        .wm-legend .meta {
            color: #94a3b8; font-size: 10px; margin-top: 6px;
            line-height: 1.4;
        }

        /* Contrôles Leaflet — esprit dark */
        .leaflet-control-zoom a {
            background: #1e293b !important; color: #94a3b8 !important;
            border-color: #334155 !important;
        }
        .leaflet-control-zoom a:hover { background: #334155 !important; color: white !important; }
        .leaflet-bar { border-color: #334155 !important; box-shadow: 0 2px 8px rgba(0,0,0,.4) !important; }
    </style>
    @endpush

    <div id="wm-root">

        {{-- Toolbar --}}
        <div id="wm-toolbar">
            <label class="field">
                Variable
                {{-- Peuplé dynamiquement depuis le manifest du sidecar --}}
                <select id="f-variable"><option>Chargement…</option></select>
            </label>
            <label class="field chk">
                <input type="checkbox" id="f-show-arrows" checked>
                <span><i class="fa-solid fa-arrow-right-long"></i> Flèches de vent</span>
            </label>
            <label class="field">
                Fond
                <select id="f-basemap">
                    <option value="satellite" selected>Satellite + noms</option>
                    <option value="topo">OpenTopoMap (relief)</option>
                    <option value="osm">OSM standard</option>
                    <option value="light">Clair</option>
                    <option value="dark">Sombre</option>
                </select>
            </label>
            <label class="field" style="min-width:140px;">
                Opacité
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="range" id="f-opacity" min="0.1" max="1" step="0.05" value="0.5"
                           style="min-width:90px;">
                    <span id="f-opacity-label" style="font-family:'DM Mono',monospace; font-size:11px; color:#cbd5e1; min-width:32px; text-align:right;">50%</span>
                </div>
            </label>
            <label class="field" style="min-width:160px;">
                Jour
                <select id="f-day"><option>—</option></select>
            </label>
            <label class="field" style="min-width:90px;">
                Heure
                <select id="f-hour"><option>—</option></select>
            </label>
            <label class="field">
                Lecture
                <button type="button" id="f-play" title="Lecture / Pause (espace)">
                    <i class="fa-solid fa-play"></i><span id="f-play-label">Play</span>
                </button>
            </label>
            <label class="field" style="min-width:110px;">
                Cadence
                <select id="f-speed">
                    <option value="500">0.5 s / h</option>
                    <option value="1000" selected>1 s / h</option>
                    <option value="2000">2 s / h</option>
                    <option value="5000">5 s / h</option>
                </select>
            </label>

            <div id="wm-status">
                <span class="pill" id="s-sidecar">sidecar —</span>
                <span class="pill" id="s-run">run —</span>
                <span class="pill" id="s-progress" style="display:none"></span>
                <span class="pill" id="s-overlay">overlay —</span>
                <span class="pill" id="s-arrows">flèches —</span>
            </div>
        </div>

        <div id="wm-map"></div>
    </div>

    @push('scripts')
    <script>
        (function init() {
            if (typeof window.L === 'undefined') {
                setTimeout(init, 50);
                return;
            }

            const ROUTES = {
                manifest: @json(route('weather-map.manifest')),
                overlay:  @json(url('/carte-meteo/overlay')),  // + /{variable}/{step}.png
                health:   @json(route('weather-map.health')),
                progress: @json(route('weather-map.progress')),
            };

            // ── Carte Leaflet ──────────────────────────────────────
            const map = L.map('wm-map', {
                preferCanvas: false,
                zoomControl: true,
                attributionControl: false,
            }).setView([46.5, 2.5], 6);

            // Cohérent avec map/_partials/scripts/config.blade.php (carte de
            // volabilité). Le défaut est OpenTopoMap : son relief ombré +
            // contraste naturel se lisent très bien sous les overlays
            // semi-transparents. Le mode satellite combine deux couches
            // Esri — l'image (World_Imagery) ET un calque de noms
            // (World_Boundaries_and_Places) servi en transparent, pour
            // garder villes, frontières et toponymes lisibles.
            const TILE_LAYERS = {
                topo:      { url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',                                                  subdomains: 'abc'  },
                osm:       { url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',                                                subdomains: 'abc'  },
                satellite: {
                    url:       'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                    subdomains: '',
                    labelsUrl: 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
                },
                light:     { url: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',                          subdomains: 'abcd' },
                dark:      { url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',                                     subdomains: 'abcd' },
            };

            // Pane dédié aux labels carto : entre le pane d'overlay et celui
            // des flèches, pour que les noms de villes restent lisibles
            // par-dessus les couches d'info (vent, précipitations, etc.).
            map.createPane('wm-labels');
            map.getPane('wm-labels').style.zIndex = 380;
            map.getPane('wm-labels').style.pointerEvents = 'none';

            let currentTile   = null;
            let currentLabels = null;

            function setBaseLayer(theme) {
                if (currentTile)   { map.removeLayer(currentTile);   currentTile = null; }
                if (currentLabels) { map.removeLayer(currentLabels); currentLabels = null; }

                const cfg = TILE_LAYERS[theme] || TILE_LAYERS.topo;
                currentTile = L.tileLayer(cfg.url, {
                    maxZoom: 18,
                    subdomains: cfg.subdomains,
                }).addTo(map);

                if (cfg.labelsUrl) {
                    currentLabels = L.tileLayer(cfg.labelsUrl, {
                        maxZoom: 18,
                        pane: 'wm-labels',
                    }).addTo(map);
                }
            }
            setBaseLayer('satellite');

            // Opacité de l'overlay info, contrôlée par le slider. On stocke
            // dans une variable pour pouvoir la ré-appliquer à chaque redraw
            // (sinon créer un nouveau L.imageOverlay reset l'opacité au défaut).
            let overlayOpacity = parseFloat(document.getElementById('f-opacity').value);

            // Pane dédié pour l'overlay (au-dessus des tiles, sous les contrôles)
            map.createPane('wm-overlay');
            map.getPane('wm-overlay').style.zIndex = 350;
            map.getPane('wm-overlay').style.pointerEvents = 'none';

            // Pane des flèches (au-dessus de l'overlay)
            map.createPane('wm-arrows');
            map.getPane('wm-arrows').style.zIndex = 400;
            map.getPane('wm-arrows').style.pointerEvents = 'none';
            const arrowsLayer = L.layerGroup([], { pane: 'wm-arrows' }).addTo(map);

            setTimeout(() => map.invalidateSize(), 0);
            window.addEventListener('resize', () => map.invalidateSize());

            // ── Légende ────────────────────────────────────────────
            const LegendControl = L.Control.extend({
                options: { position: 'bottomleft' },
                onAdd: function () {
                    this._div = L.DomUtil.create('div', 'wm-legend');
                    L.DomEvent.disableClickPropagation(this._div);
                    L.DomEvent.disableScrollPropagation(this._div);
                    return this._div;
                },
                update: function (html) { if (this._div) this._div.innerHTML = html; },
            });
            const legend = new LegendControl();
            legend.addTo(map);

            // ── État global ────────────────────────────────────────
            let manifest = null;       // payload brut /v1/overlay
            let bounds   = null;       // L.LatLngBounds calculé une fois
            let overlay  = null;       // L.ImageOverlay actif
            let currentStepIx = 0;     // index dans manifest.steps_hours
            let stepIndex = [];        // [{ stepH, dateKey, hourStr }, …]
            let stepsByDay = {};       // { dateKey: [{ stepH, hourStr, ix }, …] }
            let playTimer = null;      // setInterval du player

            // Construit l'URL d'un PNG d'overlay avec le cache-buster ?run=.
            // Le sidecar ignore ce paramètre ; nginx l'inclut dans la clé
            // de cache. Conséquence : un nouveau run → URL différente →
            // cache miss naturel, sans purge explicite ni risque de stale.
            function pngUrl(variable, stepH) {
                const run = (manifest && manifest.run_init_unix) || 0;
                return `${ROUTES.overlay}/${encodeURIComponent(variable)}/${stepH}.png?run=${run}`;
            }

            // ── Helpers --------------------------------------------
            function variableInfo(name) {
                if (!manifest) return null;
                return (manifest.variables || []).find(v => v.name === name) || null;
            }

            // Libellé humain pour une variable. Le sidecar ne renvoie pas
            // de label FR — on les map ici. Les variables non listées
            // tombent sur le nom technique (fallback).
            const VAR_LABELS = {
                // ── Couches de surface (10 m / 2 m) ─────────────────
                wind_speed_10m:              'Vent au sol — moyen (km/h)',
                wind_gusts_10m:              'Vent au sol — rafales (km/h)',
                wind_direction_10m:          'Vent au sol — direction (°)',
                precipitation:               'Précipitations (mm/h)',
                relative_humidity_2m:        'Humidité de l\'air (%)',
                temperature_2m:              'Température au sol (°C)',
                dew_point_2m:                'Point de rosée (°C)',
                cloud_cover_low:             'Couverture nuageuse — basse (%)',
                cloud_cover_mid:             'Couverture nuageuse — moyenne (%)',
                cloud_cover_high:            'Couverture nuageuse — haute (%)',
                // ── Couches d'altitude (~1500 m / 850 hPa) ──────────
                temperature_850hPa:          'Température à 1500 m (°C)',
                wind_speed_850hPa:           'Vent à 1500 m — moyen (km/h)',
                wind_direction_850hPa:       'Vent à 1500 m — direction (°)',
                // ── Indicateurs convectifs / orageux ────────────────
                cape:                        'Énergie convective — CAPE (J/kg)',
                convective_inhibition:       'Inhibition convective — CIN (J/kg)',
                lifted_index:                'Indice de stabilité — LI (K)',
                convective_precipitation:    'Précipitations orageuses (mm/h)',
                boundary_layer_height:       'Plafond thermique — couche limite (m)',
                // ── Variables propriétaires Qui-Vole ────────────────
                qui_vole_cloud_base:         'Base des nuages — plafond de vol (m)',
                qui_vole_storm_risk:         'Risque orageux (0 nul → 3 fort)',
                qui_vole_models_count:       'Modèles disponibles',
                qui_vole_models_converging:  'Modèles convergents',
            };

            // Index tolérant pour le lookup : normalise (lowercase + retire
            // les underscores) pour matcher des variantes comme `dewpoint_2m`
            // ↔ `dew_point_2m`, `temperature_850hpa` ↔ `temperature_850hPa`,
            // `qui_vol_storm_risk` ↔ `qui_vole_storm_risk`.
            const _normalize = s => String(s).toLowerCase().replace(/_/g, '');
            const VAR_LABELS_LOOSE = Object.fromEntries(
                Object.entries(VAR_LABELS).map(([k, v]) => [_normalize(k), v])
            );

            function variableLabel(name) {
                if (!name) return '';
                if (VAR_LABELS[name]) return VAR_LABELS[name];
                const loose = VAR_LABELS_LOOSE[_normalize(name)];
                return loose || name;
            }

            // Palette de repli **sémantique** par variable — utilisée quand
            // le `cmap` renvoyé par le manifest est un nom custom inconnu
            // (typiquement les `*_alpha` du sidecar). On donne ici LA palette
            // qui doit être visuellement affichée dans la légende, indépen-
            // damment du nom technique de la cmap. Ainsi un overlay bleu de
            // précipitations affiche bien une légende bleue, pas vert→rouge.
            const VAR_DEFAULT_CMAP = {
                wind_speed_10m:              'RdYlGn_r',
                wind_gusts_10m:              'RdYlGn_r',
                wind_direction_10m:          'hsv',
                precipitation:               'Blues',
                relative_humidity_2m:        'Blues',
                temperature_2m:              'RdBu_r',
                dew_point_2m:                'BrBG',
                cloud_cover_low:             'Greys',
                cloud_cover_mid:             'Greys',
                cloud_cover_high:            'Greys',
                temperature_850hPa:          'RdBu_r',
                wind_speed_850hPa:           'RdYlGn_r',
                wind_direction_850hPa:       'hsv',
                cape:                        'Reds',
                convective_inhibition:       'Blues',
                lifted_index:                'RdBu_r',
                convective_precipitation:    'Reds',
                boundary_layer_height:       'viridis',
                qui_vole_cloud_base:         'Blues_r',
                qui_vole_storm_risk:         'storm_alpha',
                qui_vole_models_count:       'RdYlGn',
                qui_vole_models_converging:  'RdYlGn',
            };

            // Dégradés CSS pour la légende — calqués (à la louche) sur les
            // colormaps matplotlib utilisées côté sidecar. Sert juste à
            // donner un repère visuel ; la vérité-terrain reste le PNG.
            // Pour les colormaps custom à canal alpha (clouds_alpha, etc.),
            // on simule en superposant la teinte sur un damier transparent.
            const CMAP_CSS = {
                // ── matplotlib séquentielles linéaires ───────────────
                'RdYlGn_r':   'linear-gradient(to right, #006837, #a6d96a, #ffffbf, #fdae61, #d73027)',
                'RdYlGn':     'linear-gradient(to right, #d73027, #fdae61, #ffffbf, #a6d96a, #006837)',
                'Blues':      'linear-gradient(to right, #f7fbff, #6baed6, #08306b)',
                'Blues_r':    'linear-gradient(to right, #08306b, #6baed6, #f7fbff)',
                'Reds':       'linear-gradient(to right, #fff5f0, #fb6a4a, #67000d)',
                'Greys':      'linear-gradient(to right, #ffffff, #969696, #000000)',
                'RdBu_r':     'linear-gradient(to right, #053061, #67a9cf, #f7f7f7, #ef8a62, #67001f)',
                'RdBu':       'linear-gradient(to right, #67001f, #ef8a62, #f7f7f7, #67a9cf, #053061)',
                'BrBG':       'linear-gradient(to right, #543005, #dfc27d, #f5f5f5, #80cdc1, #003c30)',
                'viridis':    'linear-gradient(to right, #440154, #3b528b, #21918c, #5ec962, #fde725)',
                'hsv':        'linear-gradient(to right, red, yellow, lime, cyan, blue, magenta, red)',
                // ── customs alpha-encodées (sidecar) ─────────────────
                // Nom exact dans le manifest susceptible de varier — résolu
                // via cmapCss() qui matche aussi en heuristique (suffixe
                // _alpha, _r, etc.) puis tombe sur VAR_DEFAULT_CMAP.
                'clouds_alpha':         'linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,1))',
                'rain_alpha':           'linear-gradient(to right, rgba(8,48,107,0), rgba(8,48,107,1))',
                'precipitation_alpha':  'linear-gradient(to right, rgba(8,48,107,0), rgba(8,48,107,1))',
                'storm_alpha':          'linear-gradient(to right, rgba(34,197,94,0), rgba(250,204,21,0.5), rgba(249,115,22,0.8), rgba(220,38,38,1))',
            };

            // Résout un dégradé CSS pour la légende. Ordre de résolution :
            //   1. nom exact du manifest dans CMAP_CSS
            //   2. nom sans suffixe `_alpha` (alpha-encodées du sidecar)
            //   3. palette par défaut sémantique pour cette variable
            //   4. fallback générique RdYlGn_r
            // Même normalisation tolérante pour la palette de repli.
            const VAR_DEFAULT_CMAP_LOOSE = Object.fromEntries(
                Object.entries(VAR_DEFAULT_CMAP).map(([k, v]) => [_normalize(k), v])
            );

            function cmapCss(cmapName, variableName) {
                if (cmapName && CMAP_CSS[cmapName]) return CMAP_CSS[cmapName];
                if (cmapName && cmapName.endsWith('_alpha')) {
                    const base = cmapName.slice(0, -'_alpha'.length);
                    if (CMAP_CSS[base]) return CMAP_CSS[base];
                }
                if (variableName) {
                    const fb = VAR_DEFAULT_CMAP[variableName]
                            || VAR_DEFAULT_CMAP_LOOSE[_normalize(variableName)];
                    if (fb && CMAP_CSS[fb]) return CMAP_CSS[fb];
                }
                return CMAP_CSS['RdYlGn_r'];
            }

            // Convertit un step (heures depuis run_init UTC) en (dateKey, hour)
            // exprimés en heure de Paris. dateKey = "YYYY-MM-DD" (Paris).
            function stepToParisDateHour(stepHours) {
                if (!manifest || !manifest.run_init_iso) return null;
                const base = new Date(manifest.run_init_iso);
                const dt = new Date(base.getTime() + stepHours * 3600_000);
                // Parts en TZ Paris : on construit un sub-format ISO "fr-CA"
                // (qui sort YYYY-MM-DD) + format hour:minute en clair.
                const dateKey = dt.toLocaleDateString('fr-CA', { timeZone: 'Europe/Paris' });
                const hourStr = dt.toLocaleTimeString('fr-FR', {
                    timeZone: 'Europe/Paris',
                    hour: '2-digit', minute: '2-digit', hour12: false,
                });
                return { dateKey, hourStr, dt };
            }

            // Libellé humain pour un dateKey ISO Paris (relatif quand utile).
            function fmtDayLabel(dateKey) {
                // Aujourd'hui / Demain / sinon nom complet
                const now = new Date();
                const todayKey = now.toLocaleDateString('fr-CA', { timeZone: 'Europe/Paris' });
                const tomorrow = new Date(now.getTime() + 86_400_000);
                const tomorrowKey = tomorrow.toLocaleDateString('fr-CA', { timeZone: 'Europe/Paris' });
                // Pour le label long, on reconstruit une Date à midi Paris pour
                // éviter les surprises de DST sur le formatage.
                const [y, m, d] = dateKey.split('-').map(Number);
                const sample = new Date(Date.UTC(y, m - 1, d, 12, 0, 0));
                const txt = sample.toLocaleDateString('fr-FR', {
                    timeZone: 'Europe/Paris',
                    weekday: 'long', day: '2-digit', month: 'long',
                });
                if (dateKey === todayKey)    return `Aujourd'hui · ${txt}`;
                if (dateKey === tomorrowKey) return `Demain · ${txt}`;
                return txt;
            }

            // ── Flèches de vent : lecture client-side du PNG ───────
            // wind_direction_10m est colorisé avec la cmap HSV de matplotlib.
            // La cmap HSV mappe linéairement input → teinte H (0-360°). Donc
            // chaque pixel RGB s'inverse en H qui EST l'angle de direction
            // (en convention météo FROM).
            //
            // Densité adaptative : on espace les flèches d'environ ARROW_PX_SPACING
            // pixels écran, recalculé à chaque pan/zoom. Plafonné à ARROW_MAX
            // pour éviter de noyer la carte (et économiser le rendu DOM).
            //
            // Espacement 30 px ≈ 1 flèche tous les ~1 cm écran (≈ ×4 de
            // densité par rapport au réglage initial 60 px). Le SVG en stroke
            // uniquement encaisse facilement, on plafonne à 6000 markers.
            const ARROW_PX_SPACING = 30;
            const ARROW_MAX        = 6000;
            let arrowCanvas = null;       // canvas offscreen pour decode pixels
            const arrowCache = new Map(); // step → ImageData

            function rgbToHue(r, g, b) {
                r /= 255; g /= 255; b /= 255;
                const max = Math.max(r, g, b);
                const min = Math.min(r, g, b);
                if (max === min) return null; // gris / transparent → pas de direction
                const d = max - min;
                let h;
                if (max === r)      h = ((g - b) / d) % 6;
                else if (max === g) h = (b - r) / d + 2;
                else                h = (r - g) / d + 4;
                h *= 60;
                if (h < 0) h += 360;
                return h;
            }

            function makeArrowIcon(direction) {
                // La flèche pointe où le vent VA (+180° par rapport au FROM).
                // direction = FROM (convention météo), on ajoute 180° pour
                // que la flèche indique le sens du flux — cohérent avec la
                // carte de volabilité.
                const angle = ((direction + 180) % 360 + 360) % 360;
                const path = 'M11 18 V4 M6 10 L11 4 L16 10';
                const svg = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22"
                         style="transform: rotate(${angle}deg); transform-origin: 11px 11px;">
                        <path d="${path}" fill="none" stroke="rgba(255,255,255,0.9)" stroke-width="3"   stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="${path}" fill="none" stroke="rgba(15,23,42,0.95)"   stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>`;
                return L.divIcon({
                    className: 'wm-arrow-icon',
                    html: svg,
                    iconSize: [22, 22],
                    iconAnchor: [11, 11],
                });
            }

            function loadImage(url) {
                return new Promise((resolve, reject) => {
                    const i = new Image();
                    i.crossOrigin = 'anonymous';
                    i.onload  = () => resolve(i);
                    i.onerror = () => reject(new Error('image load failed: ' + url));
                    i.src = url;
                });
            }

            // Retry 3× avec backoff sur la MÊME URL — l'URL inclut déjà
            // ?run=<run_init_unix> via pngUrl(), qui change automati-
            // quement à chaque nouveau run du sidecar (= invalidation
            // implicite). nginx dédupliquera les retries identiques via
            // `proxy_cache_lock`.
            async function loadDirectionImage(stepH) {
                if (arrowCache.has(stepH)) return arrowCache.get(stepH);
                const url = pngUrl('wind_direction_10m', stepH);
                let lastErr = null;
                for (let attempt = 0; attempt < 3; attempt++) {
                    try {
                        const img = await loadImage(url);
                        if (!arrowCanvas) arrowCanvas = document.createElement('canvas');
                        arrowCanvas.width  = img.width;
                        arrowCanvas.height = img.height;
                        const ctx = arrowCanvas.getContext('2d', { willReadFrequently: true });
                        ctx.drawImage(img, 0, 0);
                        const data = ctx.getImageData(0, 0, img.width, img.height);
                        arrowCache.set(stepH, data);
                        return data;
                    } catch (e) {
                        lastErr = e;
                        await new Promise(r => setTimeout(r, 300 * (attempt + 1)));
                    }
                }
                throw lastErr;
            }

            // Calcule la zone et la grille où placer des flèches : intersection
            // (viewport ∩ bbox du modèle), avec une densité ≈ ARROW_PX_SPACING
            // pixels écran entre deux flèches.
            function computeArrowGrid() {
                if (!manifest || !bounds) return null;
                const m = map.getBounds();
                const b = manifest.bbox;
                const latS = Math.max(m.getSouth(), b.lat_min);
                const latN = Math.min(m.getNorth(), b.lat_max);
                const lonW = Math.max(m.getWest(),  b.lon_min);
                const lonE = Math.min(m.getEast(),  b.lon_max);
                if (latS >= latN || lonW >= lonE) return null;

                const swPx = map.latLngToContainerPoint([latS, lonW]);
                const nePx = map.latLngToContainerPoint([latN, lonE]);
                const pxW = Math.abs(nePx.x - swPx.x);
                const pxH = Math.abs(swPx.y - nePx.y);
                let cols = Math.max(2, Math.round(pxW / ARROW_PX_SPACING));
                let rows = Math.max(2, Math.round(pxH / ARROW_PX_SPACING));
                // Cap dur sur le total — au-delà ça noie la carte et plombe le DOM.
                while (cols * rows > ARROW_MAX) { cols = Math.floor(cols * 0.9); rows = Math.floor(rows * 0.9); }
                return { latS, latN, lonW, lonE, cols, rows };
            }

            // Token de séquence pour drawArrows : si l'utilisateur change
            // de step pendant qu'on attend le PNG direction, on annule
            // le rendu en cours (évite des arrows fantômes ou un clear
            // qui efface ceux du nouveau step).
            let arrowsDrawSeq = 0;

            async function drawArrows() {
                const mySeq = ++arrowsDrawSeq;
                arrowsLayer.clearLayers();

                const pill = document.getElementById('s-arrows');
                if (!document.getElementById('f-show-arrows').checked) {
                    pill.textContent = 'flèches off';
                    pill.className = 'pill';
                    return;
                }
                if (!manifest || !bounds) return;

                const grid = computeArrowGrid();
                if (!grid) {
                    pill.textContent = 'flèches : hors zone';
                    pill.className = 'pill warn';
                    return;
                }

                const stepH = manifest.steps_hours[currentStepIx] ?? 0;
                let pixels;
                try {
                    pixels = await loadDirectionImage(stepH);
                } catch (e) {
                    if (mySeq !== arrowsDrawSeq) return; // step changé entre temps
                    console.warn('drawArrows: direction PNG failed', stepH, e);
                    pill.textContent = 'flèches HS';
                    pill.className = 'pill err';
                    return;
                }
                if (mySeq !== arrowsDrawSeq) return; // step changé pendant l'await

                const { lat_min, lat_max, lon_min, lon_max } = manifest.bbox;
                const { latS, latN, lonW, lonE, cols, rows } = grid;
                const W = pixels.width, H = pixels.height;
                const arr = pixels.data; // RGBA
                let drawn = 0;

                for (let row = 0; row < rows; row++) {
                    const lat = latN - ((row + 0.5) / rows) * (latN - latS);
                    const py  = Math.min(H - 1, Math.max(0, Math.round(((lat_max - lat) / (lat_max - lat_min)) * (H - 1))));
                    for (let col = 0; col < cols; col++) {
                        const lng = lonW + ((col + 0.5) / cols) * (lonE - lonW);
                        const px  = Math.min(W - 1, Math.max(0, Math.round(((lng - lon_min) / (lon_max - lon_min)) * (W - 1))));
                        const idx = (py * W + px) * 4;
                        const r = arr[idx], g = arr[idx + 1], b = arr[idx + 2], a = arr[idx + 3];
                        if (a < 32) continue;
                        const hue = rgbToHue(r, g, b);
                        if (hue === null) continue;
                        L.marker([lat, lng], {
                            icon: makeArrowIcon(hue),
                            pane: 'wm-arrows',
                            interactive: false,
                            keyboard: false,
                        }).addTo(arrowsLayer);
                        drawn++;
                    }
                }

                if (mySeq !== arrowsDrawSeq) {
                    arrowsLayer.clearLayers();
                    return;
                }
                pill.textContent = `flèches : ${drawn} pts`;
                pill.className = 'pill ok';
            }

            // ── Rendu de l'overlay courant ─────────────────────────
            // Avec le proxy_cache nginx, les PNG sont quasi instantanés en
            // cache hit. Quand le user scrub vite, `overlay.setUrl(newURL)`
            // annule la requête précédente → Leaflet émet `error` pour ce
            // load annulé. On ne doit PAS retry sur ces erreurs-là (sinon
            // on clobber le rendu avec une URL périmée).
            //
            // Stratégie : on garde la dernière URL souhaitée (`overlayUrl`).
            // Sur error, on compare l'URL effectivement chargée par Leaflet
            // à la dernière souhaitée — si elles diffèrent, c'est une
            // annulation, on ignore. Si elles correspondent, c'est un vrai
            // échec réseau → retry une fois sur la même URL (le cache nginx
            // dédupliquera côté serveur).
            let overlayUrl = null;
            let overlayRetryDone = false;

            function drawOverlay() {
                if (!manifest || !bounds) return;
                const variable = document.getElementById('f-variable').value;
                const stepH    = manifest.steps_hours[currentStepIx] ?? 0;
                const url      = pngUrl(variable, stepH);
                overlayUrl = url;
                overlayRetryDone = false;

                if (overlay) {
                    overlay.setUrl(url);
                    overlay.setOpacity(overlayOpacity);
                } else {
                    overlay = L.imageOverlay(url, bounds, {
                        opacity: overlayOpacity,
                        pane: 'wm-overlay',
                        interactive: false,
                        className: 'wm-overlay-img',
                    }).addTo(map);

                    overlay.on('error', (e) => {
                        // e.sourceTarget ou overlay._url contient l'URL qui a échoué.
                        // Si c'est une URL périmée (le user a déjà passé au suivant),
                        // on ignore — le prochain setUrl fera le boulot.
                        const failedUrl = (e && e.sourceTarget && e.sourceTarget._url) || (overlay && overlay._url);
                        if (failedUrl !== overlayUrl) return;
                        if (overlayRetryDone) {
                            const p = document.getElementById('s-overlay');
                            p.textContent = 'overlay HS';
                            p.className = 'pill err';
                            return;
                        }
                        // Un seul retry, même URL (nginx dédupliquera).
                        overlayRetryDone = true;
                        setTimeout(() => {
                            if (overlay && overlayUrl === failedUrl) overlay.setUrl(overlayUrl);
                        }, 400);
                    });
                    overlay.on('load', () => {
                        overlayRetryDone = false;
                        const p = document.getElementById('s-overlay');
                        if (p) { p.className = 'pill ok'; }
                    });
                }

                const pill = document.getElementById('s-overlay');
                pill.textContent = `${variable} · +${stepH} h`;
                pill.className = 'pill ok';
            }

            // ── Légende ────────────────────────────────────────────

            // Récupère les stops de cmap renvoyés par le sidecar dans
            // le manifest, quelle que soit la forme. Le sidecar peut
            // exposer le champ à plusieurs niveaux selon l'endpoint :
            //   - /v1/overlay              → info.cmap_stops  ou  info.stops
            //   - /v1/overlay/{variable}   → info.palette.stops
            // On tente les trois localisations possibles, et on retombe
            // sur l'ancienne table CMAP_CSS si rien n'est trouvé.
            function getCmapStops(info) {
                if (!info) return null;
                const s = info.cmap_stops || info.stops
                       || (info.palette && info.palette.stops);
                return Array.isArray(s) && s.length >= 2 ? s : null;
            }

            // Convertit un hex `#rrggbb` en {r,g,b} entiers 0..255.
            function hexToRgb(hex) {
                const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
                if (!m) return { r: 0, g: 0, b: 0 };
                return { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) };
            }

            // Convertit la liste de stops en linear-gradient CSS.
            //
            // Cas particulier alpha-encoded : si toutes les stops ont la
            // MÊME couleur RGB, c'est qu'on est sur une cmap qui ne varie
            // que par alpha (clouds_alpha, cape_alpha, etc.) et que le
            // sidecar a dropped le canal alpha au sampling. On bascule
            // alors sur un fondu "transparent → couleur opaque" qui rend
            // visuellement le bon message (basse intensité = transparent,
            // haute intensité = opaque).
            function stopsToCss(stops) {
                if (!stops || stops.length < 2) return null;

                const uniqueColors = new Set(stops.map(s => (s.color || '').toLowerCase()));
                if (uniqueColors.size === 1) {
                    const { r, g, b } = hexToRgb(stops[0].color);
                    return `linear-gradient(to right, rgba(${r},${g},${b},0), rgba(${r},${g},${b},1))`;
                }

                const sorted = [...stops].sort((a, b) => (a.t ?? 0) - (b.t ?? 0));
                const parts = sorted.map(s => `${s.color} ${(((s.t ?? 0)) * 100).toFixed(1)}%`);
                return `linear-gradient(to right, ${parts.join(', ')})`;
            }

            function renderLegend() {
                const variable = document.getElementById('f-variable').value;
                const info = variableInfo(variable);
                if (!info) {
                    legend.update('<em style="color:#64748b">Pas de variable sélectionnée</em>');
                    return;
                }
                // 1. priorité : stops fournies par le sidecar (= vérité-terrain)
                // 2. fallback : table CSS hardcodée + heuristique par variable
                const stops = getCmapStops(info);
                const grad  = (stops && stopsToCss(stops)) || cmapCss(info.cmap, variable);

                let html = `<h4>${variableLabel(variable)}</h4>`;
                html += `<div class="scale" style="background:${grad}"></div>`;

                // Formatage des bornes vmin/vmax. Le sidecar stocke en SI
                // (vent en m/s, température en °C, etc.) mais on affiche
                // en unités usuelles parapente :
                //   - vents : conversion m/s → km/h (× 3.6), entiers
                //   - catégoriel storm_risk : entiers 0..3
                //   - tout le reste : 1 décimale si non entier, sinon brut
                const WIND_SPEED_VARS = ['wind_speed_10m', 'wind_gusts_10m', 'wind_speed_850hPa'];
                const isWindSpeed = WIND_SPEED_VARS.includes(variable);
                const isStormRisk = variable === 'qui_vole_storm_risk';

                let vmin = info.vmin, vmax = info.vmax;
                let fmt;
                if (isWindSpeed) {
                    vmin = info.vmin * 3.6;
                    vmax = info.vmax * 3.6;
                    fmt  = v => String(Math.round(v));
                } else if (isStormRisk) {
                    fmt  = v => String(Math.round(v));
                } else {
                    fmt  = v => Number.isInteger(v) ? String(v) : v.toFixed(1).replace(/\.0$/, '');
                }

                // 5 graduations évenly-spaced (vmin + 3 intermédiaires + vmax).
                // Cas storm_risk : 4 graduations (0/1/2/3) pour matcher les
                // niveaux catégoriels du risque orageux.
                const nTicks = isStormRisk ? 4 : 5;
                const ticks = [];
                for (let i = 0; i < nTicks; i++) {
                    const t = i / (nTicks - 1);
                    ticks.push(vmin + t * (vmax - vmin));
                }
                html += '<div class="axis">' + ticks.map(t => `<span>${fmt(t)}</span>`).join('') + '</div>';
                html += `<div class="meta">Palette : <code>${info.cmap}</code>${stops ? ' · ' + stops.length + ' stops' : ''}</div>`;
                if (manifest && manifest.run_init_iso) {
                    const init = new Date(manifest.run_init_iso);
                    const txt  = init.toLocaleString('fr-FR', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit', timeZone:'Europe/Paris' });
                    html += `<div class="meta">Run : ${txt} (heure de Paris)</div>`;
                }
                legend.update(html);
            }

            // ── Peuplement de la toolbar à partir du manifest ──────
            function populateUI() {
                if (!manifest) return;

                // Variables — on filtre wind_direction_10m : sa palette HSV est
                // codée en couleurs vives peu lisibles à l'œil, et de toute façon
                // elle sert de source data pour la couche flèches (cf. drawArrows).
                const sel = document.getElementById('f-variable');
                sel.innerHTML = '';
                (manifest.variables || [])
                    .filter(v => v.name !== 'wind_direction_10m')
                    .forEach(v => {
                        const opt = document.createElement('option');
                        opt.value = v.name;
                        opt.textContent = variableLabel(v.name);
                        sel.appendChild(opt);
                    });
                if (sel.querySelector('option[value="wind_speed_10m"]')) {
                    sel.value = 'wind_speed_10m';
                }


                // Steps → table indexée par (dateKey, hourStr)
                const steps = manifest.steps_hours || [];
                stepsByDay = {};   // { dateKey: [{ stepH, hourStr, ix }, …] }
                stepIndex = [];    // [{ stepH, dateKey, hourStr }, …] (aligné sur steps)
                steps.forEach((stepH, ix) => {
                    const info = stepToParisDateHour(stepH);
                    if (!info) return;
                    stepIndex[ix] = { stepH, dateKey: info.dateKey, hourStr: info.hourStr };
                    if (!stepsByDay[info.dateKey]) stepsByDay[info.dateKey] = [];
                    stepsByDay[info.dateKey].push({ stepH, hourStr: info.hourStr, ix });
                });

                if (currentStepIx >= stepIndex.length) currentStepIx = 0;
                populateDayHourSelects();

                // Bounds
                const b = manifest.bbox;
                if (b && b.lat_min != null) {
                    bounds = L.latLngBounds([b.lat_min, b.lon_min], [b.lat_max, b.lon_max]);
                    // Au premier chargement, on centre la vue sur la bbox
                    if (!overlay) map.fitBounds(bounds, { padding: [20, 20], maxZoom: 7 });
                }

                // Run info
                if (manifest.run_init_iso) {
                    const init = new Date(manifest.run_init_iso);
                    const txt  = init.toLocaleString('fr-FR', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit', timeZone:'Europe/Paris' });
                    const pill = document.getElementById('s-run');
                    pill.textContent = `run : ${txt}`;
                    pill.className = 'pill ok';
                }
            }

            // ── Health ─────────────────────────────────────────────
            async function refreshHealth() {
                try {
                    const r = await fetch(ROUTES.health, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    const j = await r.json();
                    const pill = document.getElementById('s-sidecar');
                    if (j.ok) {
                        pill.textContent = 'sidecar OK';
                        pill.className = 'pill ok';
                    } else {
                        pill.textContent = `sidecar HS${j.status ? ' ('+j.status+')' : ''}`;
                        pill.className = 'pill err';
                    }
                } catch (e) {
                    const pill = document.getElementById('s-sidecar');
                    pill.textContent = 'sidecar injoignable';
                    pill.className = 'pill err';
                }
            }

            async function loadManifest() {
                let resp;
                try {
                    resp = await fetch(ROUTES.manifest, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                } catch (e) {
                    document.getElementById('s-overlay').textContent = 'manifest injoignable';
                    document.getElementById('s-overlay').className = 'pill err';
                    return;
                }
                if (!resp.ok) {
                    document.getElementById('s-overlay').textContent = `manifest HS (${resp.status})`;
                    document.getElementById('s-overlay').className = 'pill err';
                    return;
                }
                manifest = await resp.json();
                populateUI();
                renderLegend();
                drawOverlay();
                drawArrows();
            }

            // Détection live d'un nouveau run du sidecar : on poll le
            // manifest toutes les 60 s, et si `run_init_unix` change, on
            // purge le cache local (arrowCache) et on relance le draw
            // + le prefetch pour fetch les nouvelles URL (qui contiennent
            // le nouveau run en query-string, donc cache miss naturel).
            async function checkForNewRun() {
                if (!manifest) return;
                try {
                    const resp = await fetch(ROUTES.manifest, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    if (!resp.ok) return;
                    const fresh = await resp.json();
                    if (fresh.run_init_unix && fresh.run_init_unix !== manifest.run_init_unix) {
                        console.info('Nouveau run sidecar détecté', fresh.run_init_iso);
                        manifest = fresh;
                        arrowCache.clear();       // ImageData de l'ancien run, plus valide
                        renderLegend();           // pour mettre à jour le timestamp run
                        // Synchronise le pill `run` avec la nouvelle valeur
                        const init = new Date(manifest.run_init_iso);
                        const txt  = init.toLocaleString('fr-FR', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit', timeZone:'Europe/Paris' });
                        const pill = document.getElementById('s-run');
                        pill.textContent = `run : ${txt}`;
                        pill.className = 'pill ok';
                        // Re-rendu de l'overlay courant (nouvelle URL → nouveau fetch)
                        drawOverlay();
                        drawArrows();
                    }
                } catch (e) { /* silencieux, on retentera */ }
            }

            // Badge `/progress` : affiche l'avancement d'un run consensus
            // en cours côté sidecar. Caché en `idle` / `completed`.
            async function refreshProgress() {
                let p;
                try {
                    const r = await fetch(ROUTES.progress, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                    p = await r.json();
                } catch (e) { return; }

                const pill = document.getElementById('s-progress');
                if (!p || p.status === 'idle' || p.status === 'completed') {
                    pill.style.display = 'none';
                    return;
                }
                if (p.status === 'failed') {
                    pill.style.display = '';
                    pill.textContent = `run échec : ${p.error || '?'}`;
                    pill.className = 'pill err';
                    return;
                }
                if (p.status === 'running') {
                    pill.style.display = '';
                    const pct = (p.variables_total > 0)
                        ? Math.round(100 * (p.variables_done || 0) / p.variables_total)
                        : 0;
                    const elapsed = Math.round((p.elapsed_s || 0) / 60);
                    const cur = p.current_variable || '…';
                    pill.textContent = `run en cours : ${cur} (${pct}%, ${elapsed} min)`;
                    pill.className = 'pill warn';
                }
            }

            // Debounce du redraw lors de changements rapprochés (player rapide,
            // etc.). Évite d'enchaîner les fetch PNG qui se marchent dessus
            // et font clignoter l'overlay.
            let stepTimer = null;
            function scheduleRedraw(delay) {
                clearTimeout(stepTimer);
                stepTimer = setTimeout(() => {
                    drawOverlay();
                    drawArrows();
                }, delay);
            }

            // Redraw des flèches uniquement (sans recharger le PNG d'info)
            // sur pan/zoom — la cache par step rend ça quasi instantané.
            let arrowsTimer = null;
            function scheduleArrowsRedraw() {
                clearTimeout(arrowsTimer);
                arrowsTimer = setTimeout(drawArrows, 100);
            }

            // ── Sélecteurs jour / heure + player ───────────────────
            function populateDayHourSelects() {
                const daySel = document.getElementById('f-day');
                const days = Object.keys(stepsByDay).sort();
                daySel.innerHTML = '';
                days.forEach(dk => {
                    const opt = document.createElement('option');
                    opt.value = dk;
                    opt.textContent = fmtDayLabel(dk);
                    daySel.appendChild(opt);
                });
                // Aligne les sélecteurs sur l'index courant
                syncSelectsToStep();
            }

            function populateHourSelect(dateKey, preferredHour) {
                const hourSel = document.getElementById('f-hour');
                const list = stepsByDay[dateKey] || [];
                hourSel.innerHTML = '';
                list.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.hourStr;
                    opt.textContent = s.hourStr;
                    opt.dataset.ix = s.ix;
                    hourSel.appendChild(opt);
                });
                // Sélectionne preferredHour si dispo, sinon la première
                if (preferredHour && list.some(s => s.hourStr === preferredHour)) {
                    hourSel.value = preferredHour;
                }
            }

            function syncSelectsToStep() {
                const cur = stepIndex[currentStepIx];
                if (!cur) return;
                document.getElementById('f-day').value = cur.dateKey;
                populateHourSelect(cur.dateKey, cur.hourStr);
                document.getElementById('f-hour').value = cur.hourStr;
            }

            function gotoStep(ix, opts = {}) {
                if (ix < 0 || ix >= stepIndex.length) return;
                currentStepIx = ix;
                if (!opts.skipSync) syncSelectsToStep();
                scheduleRedraw(opts.delay ?? 0);
            }

            function playPause() {
                const btn   = document.getElementById('f-play');
                const label = document.getElementById('f-play-label');
                if (playTimer) {
                    clearInterval(playTimer);
                    playTimer = null;
                    btn.classList.remove('playing');
                    btn.querySelector('i').className = 'fa-solid fa-play';
                    label.textContent = 'Play';
                    return;
                }
                const delay = parseInt(document.getElementById('f-speed').value, 10) || 1000;
                btn.classList.add('playing');
                btn.querySelector('i').className = 'fa-solid fa-pause';
                label.textContent = 'Pause';
                playTimer = setInterval(() => {
                    const next = (currentStepIx + 1) % stepIndex.length;
                    gotoStep(next);
                }, delay);
            }

            function stopPlaying() {
                if (playTimer) playPause();
            }

            // ── Listeners ──────────────────────────────────────────
            document.getElementById('f-variable').addEventListener('change', () => {
                renderLegend();
                drawOverlay();
            });
            document.getElementById('f-show-arrows').addEventListener('change', () => {
                drawArrows();
            });
            document.getElementById('f-basemap').addEventListener('change', (e) => {
                setBaseLayer(e.target.value);
            });
            document.getElementById('f-opacity').addEventListener('input', (e) => {
                overlayOpacity = parseFloat(e.target.value) || 0.5;
                document.getElementById('f-opacity-label').textContent =
                    Math.round(overlayOpacity * 100) + '%';
                if (overlay) overlay.setOpacity(overlayOpacity);
            });
            // Changement manuel de jour → met en pause, conserve l'heure si
            // dispo, sinon va sur la 1re heure du jour choisi.
            document.getElementById('f-day').addEventListener('change', (e) => {
                stopPlaying();
                const dk = e.target.value;
                const cur = stepIndex[currentStepIx];
                const preferred = cur ? cur.hourStr : null;
                populateHourSelect(dk, preferred);
                const hourSel = document.getElementById('f-hour');
                const ix = parseInt(hourSel.options[hourSel.selectedIndex].dataset.ix, 10);
                gotoStep(ix, { skipSync: true });
            });
            document.getElementById('f-hour').addEventListener('change', (e) => {
                stopPlaying();
                const opt = e.target.options[e.target.selectedIndex];
                const ix = parseInt(opt.dataset.ix, 10);
                gotoStep(ix, { skipSync: true });
            });
            document.getElementById('f-play').addEventListener('click', playPause);
            // Changement de cadence pendant la lecture : on relance le timer
            // pour appliquer immédiatement la nouvelle vitesse.
            document.getElementById('f-speed').addEventListener('change', () => {
                if (playTimer) { playPause(); playPause(); }
            });
            // Espace = play/pause (sauf si focus dans un select)
            window.addEventListener('keydown', (e) => {
                if (e.code !== 'Space') return;
                const tag = (document.activeElement && document.activeElement.tagName) || '';
                if (tag === 'SELECT' || tag === 'INPUT' || tag === 'TEXTAREA') return;
                e.preventDefault();
                playPause();
            });

            // Pan/zoom → recalcule la grille des flèches (densité = écran).
            // L'overlay PNG, lui, reste collé à sa bbox via Leaflet ; pas
            // besoin de le redessiner.
            map.on('moveend zoomend', scheduleArrowsRedraw);

            // Premier rendu
            refreshHealth();
            refreshProgress();
            loadManifest();
            setInterval(refreshHealth,   60_000);
            setInterval(refreshProgress, 30_000);   // suit un run actif côté sidecar
            setInterval(checkForNewRun,  60_000);   // détecte la bascule de run
        })();
    </script>
    @endpush
</x-app-shell>
