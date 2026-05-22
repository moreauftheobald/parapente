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
            <label class="field chk">
                <input type="checkbox" id="f-dark-mode">
                <span><i class="fa-solid fa-moon"></i> Fond sombre</span>
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
                <span class="pill" id="s-cache">cache —</span>
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
            };

            // ── Carte Leaflet ──────────────────────────────────────
            const map = L.map('wm-map', {
                preferCanvas: false,
                zoomControl: true,
                attributionControl: false,
            }).setView([46.5, 2.5], 6);

            const TILE_LAYERS = {
                light: 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
                dark:  'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
            };
            let currentTile = L.tileLayer(TILE_LAYERS.light, {
                maxZoom: 18,
                subdomains: 'abcd',
            }).addTo(map);

            function setBaseLayer(theme) {
                map.removeLayer(currentTile);
                currentTile = L.tileLayer(TILE_LAYERS[theme] || TILE_LAYERS.light, {
                    maxZoom: 18,
                    subdomains: 'abcd',
                }).addTo(map);
            }

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

            // ── Helpers --------------------------------------------
            function variableInfo(name) {
                if (!manifest) return null;
                return (manifest.variables || []).find(v => v.name === name) || null;
            }

            // Libellé humain pour une variable. Le sidecar ne renvoie pas
            // de label FR — on les map ici. Les variables non listées
            // tombent sur le nom technique (fallback).
            const VAR_LABELS = {
                wind_speed_10m:              'Vent moyen 10 m (km/h)',
                wind_gusts_10m:              'Rafales 10 m (km/h)',
                wind_direction_10m:          'Direction du vent (°)',
                precipitation:               'Précipitations (mm/h)',
                relative_humidity_2m:        'Humidité relative 2 m (%)',
                temperature_2m:              'Température 2 m (°C)',
                cloud_cover_low:             'Nuages bas (%)',
                cloud_cover_mid:             'Nuages moyens (%)',
                cloud_cover_high:            'Nuages hauts (%)',
                qui_vole_models_count:       'Modèles disponibles',
                qui_vole_models_converging:  'Modèles convergents',
            };
            function variableLabel(name) {
                return VAR_LABELS[name] || name;
            }

            // Dégradés CSS pour la légende — calqués (à la louche) sur les
            // colormaps matplotlib utilisées côté sidecar. Sert juste à
            // donner un repère visuel ; la vérité-terrain reste le PNG.
            const CMAP_CSS = {
                'RdYlGn_r':  'linear-gradient(to right, #006837, #a6d96a, #ffffbf, #fdae61, #d73027)',
                'RdYlGn':    'linear-gradient(to right, #d73027, #fdae61, #ffffbf, #a6d96a, #006837)',
                'Blues':     'linear-gradient(to right, #f7fbff, #6baed6, #08306b)',
                'Greys':     'linear-gradient(to right, #ffffff, #969696, #000000)',
                'RdBu_r':    'linear-gradient(to right, #053061, #67a9cf, #f7f7f7, #ef8a62, #67001f)',
                'hsv':       'linear-gradient(to right, red, yellow, lime, cyan, blue, magenta, red)',
            };

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
            const ARROW_PX_SPACING = 60;
            const ARROW_MAX        = 1500;
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
                // Convention parapente / manche à air : la flèche pointe vers
                // D'OÙ vient le vent (= valeur de direction FROM directement,
                // sans offset). Un pilote lit la manche à air comme "le vent
                // vient de là", il atterrit face à elle.
                const angle = ((direction % 360) + 360) % 360;
                const svg = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22"
                         style="transform: rotate(${angle}deg); transform-origin: 11px 11px;">
                        <path d="M11 3 L15 13 L11 11 L7 13 Z"
                              fill="rgba(15,23,42,0.85)"
                              stroke="rgba(255,255,255,0.9)" stroke-width="0.8" stroke-linejoin="round"/>
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

            // Retry 2× avec backoff + cache-buster — même problématique que
            // l'overlay : le PNG des flèches peut échouer si le sidecar
            // hoquette ou si l'Image est interrompue par un scrub rapide.
            async function loadDirectionImage(stepH) {
                if (arrowCache.has(stepH)) return arrowCache.get(stepH);
                const base = `${ROUTES.overlay}/wind_direction_10m/${stepH}.png`;
                let lastErr = null;
                for (let attempt = 0; attempt < 3; attempt++) {
                    const url = attempt === 0 ? base : `${base}?r=${Date.now()}_${attempt}`;
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

            async function drawArrows() {
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
                    pill.textContent = 'flèches HS (chargement)';
                    pill.className = 'pill err';
                    return;
                }

                const { lat_min, lat_max, lon_min, lon_max } = manifest.bbox;
                const { latS, latN, lonW, lonE, cols, rows } = grid;
                const W = pixels.width, H = pixels.height;
                const arr = pixels.data; // RGBA
                let drawn = 0;

                for (let row = 0; row < rows; row++) {
                    // 0.5 → centre de la cellule de la grille viewport
                    const lat = latN - ((row + 0.5) / rows) * (latN - latS);
                    const py  = Math.min(H - 1, Math.max(0, Math.round(((lat_max - lat) / (lat_max - lat_min)) * (H - 1))));
                    for (let col = 0; col < cols; col++) {
                        const lng = lonW + ((col + 0.5) / cols) * (lonE - lonW);
                        const px  = Math.min(W - 1, Math.max(0, Math.round(((lng - lon_min) / (lon_max - lon_min)) * (W - 1))));
                        const idx = (py * W + px) * 4;
                        const r = arr[idx], g = arr[idx + 1], b = arr[idx + 2], a = arr[idx + 3];
                        if (a < 32) continue; // pixel transparent (hors couverture)
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

                pill.textContent = `flèches : ${drawn} pts`;
                pill.className = 'pill ok';
            }

            // ── Rendu de l'overlay courant ─────────────────────────
            // L'URL en cours est gardée pour pouvoir retry sur erreur — quand
            // le user scrub vite, le navigateur annule la requête en cours
            // (img.src réassigné) et Leaflet émet `error`. Idem si le sidecar
            // hoquette sous charge. On retry 2× avec backoff + cache-buster
            // pour bypasser une éventuelle entrée d'échec en cache navigateur.
            let overlayUrl = null;
            let overlayRetryCount = 0;
            const OVERLAY_MAX_RETRIES = 2;

            function drawOverlay() {
                if (!manifest || !bounds) return;
                const variable = document.getElementById('f-variable').value;
                const stepH    = manifest.steps_hours[currentStepIx] ?? 0;
                const url      = `${ROUTES.overlay}/${encodeURIComponent(variable)}/${stepH}.png`;
                overlayUrl = url;
                overlayRetryCount = 0;

                if (overlay) {
                    overlay.setUrl(url);
                } else {
                    overlay = L.imageOverlay(url, bounds, {
                        opacity: 0.65,
                        pane: 'wm-overlay',
                        interactive: false,
                        className: 'wm-overlay-img',
                    }).addTo(map);

                    overlay.on('error', () => {
                        if (overlayRetryCount >= OVERLAY_MAX_RETRIES) {
                            const p = document.getElementById('s-overlay');
                            p.textContent = 'overlay HS (réessaie)';
                            p.className = 'pill err';
                            return;
                        }
                        overlayRetryCount++;
                        // Backoff + cache-buster pour forcer un nouveau fetch
                        // (sinon le navigateur peut servir un échec en négatif).
                        const bust = `?r=${Date.now()}`;
                        setTimeout(() => {
                            if (overlay && overlayUrl) overlay.setUrl(overlayUrl + bust);
                        }, 400 * overlayRetryCount);
                    });
                    overlay.on('load', () => {
                        overlayRetryCount = 0;
                        const p = document.getElementById('s-overlay');
                        // Mémorise l'état OK seulement si on est encore sur la
                        // même URL (pas un load tardif d'une URL périmée).
                        if (p) { p.className = 'pill ok'; }
                    });
                }

                const pill = document.getElementById('s-overlay');
                pill.textContent = `${variable} · +${stepH} h`;
                pill.className = 'pill ok';
            }

            // ── Légende ────────────────────────────────────────────
            function renderLegend() {
                const variable = document.getElementById('f-variable').value;
                const info = variableInfo(variable);
                if (!info) {
                    legend.update('<em style="color:#64748b">Pas de variable sélectionnée</em>');
                    return;
                }
                const grad = CMAP_CSS[info.cmap] || CMAP_CSS['RdYlGn_r'];
                let html = `<h4>${variableLabel(variable)}</h4>`;
                html += `<div class="scale" style="background:${grad}"></div>`;
                html += `<div class="axis"><span>${info.vmin}</span><span>${info.vmax}</span></div>`;
                html += `<div class="meta">Palette : <code>${info.cmap}</code></div>`;
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
                startPrefetch();    // warm le cache navigateur en tâche de fond
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

            // ── Préchargement de tous les PNG d'une variable ───────
            // Dès qu'on connaît le manifest, on charge en tâche de fond les
            // 121 PNG du variable courant + les 121 PNG de wind_direction_10m
            // (pour les flèches). Les PNG vont dans le cache navigateur (HTTP
            // cache `max-age=3600`) ; les direction vont aussi dans arrowCache
            // (ImageData décodée). Une fois le préchargement fini, le player
            // tourne sur du local pur, pas de fetch sidecar par step.
            //
            // Tokens de session : un changement de variable annule l'ancien
            // prefetch et en relance un autre.
            let prefetchSession = 0;

            function loadImgQuiet(url) {
                return new Promise(resolve => {
                    const i = new Image();
                    i.crossOrigin = 'anonymous';
                    i.onload  = () => resolve();
                    i.onerror = () => resolve(); // best effort : retry géré ailleurs
                    i.src = url;
                });
            }

            async function startPrefetch() {
                const session  = ++prefetchSession;
                const variable = document.getElementById('f-variable').value;
                if (!manifest || !variable) return;

                const steps = manifest.steps_hours || [];
                if (steps.length === 0) return;

                // Ordre malin : à partir du step courant, puis on wrap au début.
                // Comme ça l'utilisateur a tout de suite du cache devant lui.
                const fromIx = currentStepIx | 0;
                const ordered = steps.slice(fromIx).concat(steps.slice(0, fromIx));

                const total = ordered.length;
                let done = 0;
                const pill = document.getElementById('s-cache');
                pill.textContent = `cache 0/${total}`;
                pill.className = 'pill warn';

                const queue = ordered.slice();
                const CONCURRENT = 4;  // nginx proxy_cache encaisse, plus de bottleneck PHP-FPM

                async function worker() {
                    while (queue.length) {
                        if (session !== prefetchSession) return;
                        const stepH = queue.shift();

                        // 1. PNG du variable courant → cache navigateur uniquement
                        await loadImgQuiet(`${ROUTES.overlay}/${encodeURIComponent(variable)}/${stepH}.png`);
                        if (session !== prefetchSession) return;

                        // 2. PNG direction → décodé en ImageData dans arrowCache
                        if (!arrowCache.has(stepH)) {
                            try { await loadDirectionImage(stepH); } catch (e) { /* retry géré par drawArrows */ }
                        }
                        if (session !== prefetchSession) return;

                        done++;
                        pill.textContent = `cache ${done}/${total}`;
                    }
                }

                await Promise.all(Array.from({length: CONCURRENT}, worker));

                if (session === prefetchSession) {
                    pill.textContent = `cache prêt (${total})`;
                    pill.className = 'pill ok';
                }
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
                startPrefetch();    // recommence le warm pour la nouvelle variable
            });
            document.getElementById('f-show-arrows').addEventListener('change', () => {
                drawArrows();
            });
            document.getElementById('f-dark-mode').addEventListener('change', (e) => {
                setBaseLayer(e.target.checked ? 'dark' : 'light');
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
            loadManifest();
            setInterval(refreshHealth, 60_000);
        })();
    </script>
    @endpush
</x-app-shell>
