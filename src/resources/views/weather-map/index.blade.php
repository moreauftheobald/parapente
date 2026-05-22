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
        #wm-toolbar input[type="range"] {
            background: #1e293b; color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 6px;
            font-size: 12px; padding: 4px 8px;
            min-width: 160px;
        }
        #wm-toolbar select:focus { outline: none; border-color: #0ea5e9; }
        #wm-toolbar .chk {
            flex-direction: row; align-items: center; gap: 6px;
            color: #cbd5e1; cursor: pointer;
        }
        #wm-toolbar input[type="range"] {
            min-width: 220px; padding: 0;
        }

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

        #wm-map {
            flex: 1 1 auto;
            min-height: 0;
            position: relative;
            background: #020617;
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
            max-width: 240px;
            pointer-events: auto;
        }
        .wm-legend h4 {
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;
            color: #64748b; margin: 0 0 6px 0;
        }
        .wm-legend .row {
            display: flex; align-items: center; gap: 8px;
            margin: 2px 0;
        }

        /* Contrôles Leaflet — esprit dark cohérent avec model-grid */
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
                Couche d'info
                <select id="f-info-variable">
                    @foreach ($variables as $key => $v)
                        @if ($v['kind'] === 'info')
                            <option value="{{ $key }}" data-unit="{{ $v['unit'] }}">
                                {{ $v['label'] }}
                            </option>
                        @endif
                    @endforeach
                </select>
            </label>
            <label class="field chk">
                <input type="checkbox" id="f-show-arrows" checked>
                <span><i class="fa-solid fa-arrow-right-long"></i> Flèches de vent</span>
            </label>
            <label class="field chk">
                <input type="checkbox" id="f-dark-mode">
                <span><i class="fa-solid fa-moon"></i> Fond sombre</span>
            </label>
            <label class="field" style="flex:1 1 280px; max-width:520px;">
                Pas de temps
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="range" id="f-step" min="0" max="0" value="0" step="1" disabled>
                    <span id="wm-step-label">—</span>
                </div>
            </label>

            <div id="wm-status">
                <span class="pill" id="s-sidecar">sidecar —</span>
                <span class="pill" id="s-info">info —</span>
                <span class="pill" id="s-arrows">flèches —</span>
            </div>
        </div>

        {{-- Carte (la légende est ajoutée comme L.Control dynamique) --}}
        <div id="wm-map"></div>
    </div>

    @push('scripts')
    <script>
        (function init() {
            // window.L est exposé par resources/js/app.js (Vite bundle).
            // En cas de race rare où ce script s'exécute avant, on attend.
            if (typeof window.L === 'undefined') {
                setTimeout(init, 50);
                return;
            }

            const ROUTES = {
                manifest: @json(url('/carte-meteo/overlay')),  // + /{variable}
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

            // Pane dédiés pour contrôler l'ordre de superposition
            map.createPane('wm-info');
            map.getPane('wm-info').style.zIndex = 350;
            map.createPane('wm-arrows');
            map.getPane('wm-arrows').style.zIndex = 360;
            map.getPane('wm-arrows').style.pointerEvents = 'none';

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

            // ── Overlays (ImageOverlay rebuild à chaque step/var) ─
            let infoOverlay   = null;
            let arrowsOverlay = null;

            // Manifests par variable : { bounds: L.LatLngBounds, steps: [{step, label}], variable }
            const manifestCache = new Map();

            // Normalise les bounds renvoyés par le sidecar.
            // Accepte : {north,south,east,west} | [[s,w],[n,e]] | [s,w,n,e]
            function parseBounds(b) {
                if (!b) return null;
                if (Array.isArray(b) && b.length === 4 && typeof b[0] === 'number') {
                    return L.latLngBounds([b[0], b[1]], [b[2], b[3]]);
                }
                if (Array.isArray(b) && b.length === 2 && Array.isArray(b[0])) {
                    return L.latLngBounds(b[0], b[1]);
                }
                if (typeof b === 'object' && b.north != null) {
                    return L.latLngBounds([b.south, b.west], [b.north, b.east]);
                }
                return null;
            }

            // Normalise la liste des steps. Accepte plusieurs formes.
            function parseSteps(raw) {
                if (!Array.isArray(raw)) return [];
                return raw.map((s, i) => {
                    if (typeof s === 'string' || typeof s === 'number') {
                        return { step: String(s), label: String(s) };
                    }
                    const step  = s.step ?? s.id ?? s.key ?? String(i);
                    const label = s.valid_at ?? s.label ?? s.time ?? String(step);
                    return { step: String(step), label: String(label) };
                });
            }

            async function loadManifest(variable) {
                if (manifestCache.has(variable)) return manifestCache.get(variable);
                let resp;
                try {
                    resp = await fetch(`${ROUTES.manifest}/${encodeURIComponent(variable)}`, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                } catch (e) {
                    return { error: 'network', message: String(e) };
                }
                if (!resp.ok) {
                    return { error: 'http_' + resp.status };
                }
                const json = await resp.json();
                const bounds = parseBounds(json.bounds);
                const steps  = parseSteps(json.steps);
                const parsed = { variable, bounds, steps };
                manifestCache.set(variable, parsed);
                return parsed;
            }

            function overlayUrl(variable, step) {
                return `${ROUTES.overlay}/${encodeURIComponent(variable)}/${encodeURIComponent(step)}.png`;
            }

            // ── Slider / steps ─────────────────────────────────────
            // Source de vérité = manifest de la couche d'info (= step
            // commun aux deux overlays). On suppose que les flèches
            // ont les mêmes pas — sinon le manifest des flèches sera
            // utilisé pour la flèche correspondante.
            let currentSteps  = [];
            let currentStepIx = 0;
            const stepInput   = document.getElementById('f-step');
            const stepLabel   = document.getElementById('wm-step-label');

            function setStepRange(steps) {
                currentSteps = steps;
                if (!steps.length) {
                    stepInput.disabled = true;
                    stepInput.min = 0; stepInput.max = 0; stepInput.value = 0;
                    stepLabel.textContent = '—';
                    return;
                }
                stepInput.disabled = false;
                stepInput.min = 0;
                stepInput.max = steps.length - 1;
                if (currentStepIx >= steps.length) currentStepIx = 0;
                stepInput.value = currentStepIx;
                stepLabel.textContent = steps[currentStepIx].label;
            }

            // ── Rendu d'un overlay ─────────────────────────────────
            async function renderInfo() {
                const variable = document.getElementById('f-info-variable').value;
                document.getElementById('s-info').textContent = `info : ${variable}`;
                const m = await loadManifest(variable);
                if (m.error) {
                    if (infoOverlay) { map.removeLayer(infoOverlay); infoOverlay = null; }
                    document.getElementById('s-info').className = 'pill err';
                    document.getElementById('s-info').textContent = `info HS (${m.error})`;
                    setStepRange([]);
                    return;
                }
                document.getElementById('s-info').className = 'pill ok';
                setStepRange(m.steps);
                drawInfo(m);
                renderLegend(variable);
            }

            function drawInfo(manifest) {
                if (!manifest.bounds || !manifest.steps.length) {
                    if (infoOverlay) { map.removeLayer(infoOverlay); infoOverlay = null; }
                    return;
                }
                const step = manifest.steps[currentStepIx] || manifest.steps[0];
                const url  = overlayUrl(manifest.variable, step.step);
                if (infoOverlay) {
                    infoOverlay.setUrl(url);
                    infoOverlay.setBounds(manifest.bounds);
                } else {
                    infoOverlay = L.imageOverlay(url, manifest.bounds, {
                        opacity: 0.65,
                        pane: 'wm-info',
                        interactive: false,
                    }).addTo(map);
                }
            }

            async function renderArrows() {
                const show = document.getElementById('f-show-arrows').checked;
                if (!show) {
                    if (arrowsOverlay) { map.removeLayer(arrowsOverlay); arrowsOverlay = null; }
                    document.getElementById('s-arrows').textContent = 'flèches off';
                    document.getElementById('s-arrows').className = 'pill';
                    return;
                }
                const m = await loadManifest('wind_arrows');
                if (m.error) {
                    if (arrowsOverlay) { map.removeLayer(arrowsOverlay); arrowsOverlay = null; }
                    document.getElementById('s-arrows').textContent = `flèches HS (${m.error})`;
                    document.getElementById('s-arrows').className = 'pill err';
                    return;
                }
                document.getElementById('s-arrows').textContent = `flèches : ${m.steps.length} steps`;
                document.getElementById('s-arrows').className = 'pill ok';
                drawArrows(m);
            }

            function drawArrows(manifest) {
                if (!manifest.bounds || !manifest.steps.length) {
                    if (arrowsOverlay) { map.removeLayer(arrowsOverlay); arrowsOverlay = null; }
                    return;
                }
                // On essaie d'aligner le step sur celui de la couche info ;
                // sinon on prend le premier disponible.
                const ix = Math.min(currentStepIx, manifest.steps.length - 1);
                const step = manifest.steps[ix];
                const url  = overlayUrl(manifest.variable, step.step);
                if (arrowsOverlay) {
                    arrowsOverlay.setUrl(url);
                    arrowsOverlay.setBounds(manifest.bounds);
                } else {
                    arrowsOverlay = L.imageOverlay(url, manifest.bounds, {
                        opacity: 0.85,
                        pane: 'wm-arrows',
                        interactive: false,
                    }).addTo(map);
                }
            }

            // ── Légende ────────────────────────────────────────────
            // À enrichir une fois qu'on connaîtra les palettes exactes
            // du sidecar (probablement encodées dans les PNG eux-mêmes).
            function renderLegend(variable) {
                const sel = document.getElementById('f-info-variable');
                const opt = sel.options[sel.selectedIndex];
                const unit = opt ? opt.getAttribute('data-unit') : '';
                let html = `<h4>${opt ? opt.textContent.trim() : variable}</h4>`;
                html += `<div style="color:#94a3b8; font-size:10px; line-height:1.4;">`
                     + `Palette définie par le sidecar consensus-grid. `
                     + `Unité : ${unit || '—'}.`
                     + `</div>`;
                html += '<div style="height:1px; background:#1e293b; margin:8px 0"></div>';
                html += '<h4>Flèches de vent</h4>';
                html += `<div style="color:#94a3b8; font-size:10px;">`
                     + `Direction et intensité aux nœuds de la grille.`
                     + `</div>`;
                legend.update(html);
            }

            // ── Health badge ───────────────────────────────────────
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

            // ── Listeners ──────────────────────────────────────────
            document.getElementById('f-info-variable').addEventListener('change', () => {
                // On purge le cache du manifest pour la variable courante
                // pas nécessaire (manifests stables), juste un re-render.
                renderInfo();
            });
            document.getElementById('f-show-arrows').addEventListener('change', renderArrows);
            document.getElementById('f-dark-mode').addEventListener('change', (e) => {
                setBaseLayer(e.target.checked ? 'dark' : 'light');
            });
            stepInput.addEventListener('input', () => {
                currentStepIx = parseInt(stepInput.value, 10) || 0;
                if (currentSteps[currentStepIx]) {
                    stepLabel.textContent = currentSteps[currentStepIx].label;
                }
                // Re-applique les overlays sur le nouveau step
                const v = document.getElementById('f-info-variable').value;
                const m = manifestCache.get(v);
                if (m) drawInfo(m);
                const a = manifestCache.get('wind_arrows');
                if (a && document.getElementById('f-show-arrows').checked) drawArrows(a);
            });

            // Premier rendu
            refreshHealth();
            renderInfo().then(() => renderArrows());
            // Refresh périodique du health (toutes les 60 s)
            setInterval(refreshHealth, 60_000);
        })();
    </script>
    @endpush
</x-app-shell>
