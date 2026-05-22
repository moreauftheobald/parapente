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
            min-width: 180px;
        }
        #wm-toolbar select:focus { outline: none; border-color: #0ea5e9; }
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
                <input type="checkbox" id="f-dark-mode">
                <span><i class="fa-solid fa-moon"></i> Fond sombre</span>
            </label>
            <label class="field" style="flex:1 1 320px; max-width:560px;">
                Pas de temps
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="range" id="f-step" min="0" max="0" value="0" step="1" disabled>
                    <span id="wm-step-label">—</span>
                </div>
            </label>

            <div id="wm-status">
                <span class="pill" id="s-sidecar">sidecar —</span>
                <span class="pill" id="s-run">run —</span>
                <span class="pill" id="s-overlay">overlay —</span>
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

            function fmtStepLabel(stepHours) {
                if (!manifest || !manifest.run_init_iso) return `+${stepHours} h`;
                const base = new Date(manifest.run_init_iso);
                const dt = new Date(base.getTime() + stepHours * 3600_000);
                // Format local Paris (le sidecar travaille en UTC)
                const opts = { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' };
                const txt = dt.toLocaleString('fr-FR', opts);
                return `<span class="h">+${stepHours} h</span> ${txt}`;
            }

            // ── Rendu de l'overlay courant ─────────────────────────
            function drawOverlay() {
                if (!manifest || !bounds) return;
                const variable = document.getElementById('f-variable').value;
                const stepH    = manifest.steps_hours[currentStepIx] ?? 0;
                const url      = `${ROUTES.overlay}/${encodeURIComponent(variable)}/${stepH}.png`;

                if (overlay) {
                    overlay.setUrl(url);
                    overlay.setBounds(bounds);
                } else {
                    overlay = L.imageOverlay(url, bounds, {
                        opacity: 0.65,
                        pane: 'wm-overlay',
                        interactive: false,
                    }).addTo(map);
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

                // Variables
                const sel = document.getElementById('f-variable');
                sel.innerHTML = '';
                (manifest.variables || []).forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v.name;
                    opt.textContent = variableLabel(v.name);
                    sel.appendChild(opt);
                });

                // Steps
                const stepInput = document.getElementById('f-step');
                const steps = manifest.steps_hours || [];
                if (steps.length > 0) {
                    stepInput.disabled = false;
                    stepInput.min = 0;
                    stepInput.max = steps.length - 1;
                    if (currentStepIx >= steps.length) currentStepIx = 0;
                    stepInput.value = currentStepIx;
                    document.getElementById('wm-step-label').innerHTML = fmtStepLabel(steps[currentStepIx]);
                } else {
                    stepInput.disabled = true;
                    document.getElementById('wm-step-label').textContent = '—';
                }

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
            }

            // ── Listeners ──────────────────────────────────────────
            document.getElementById('f-variable').addEventListener('change', () => {
                renderLegend();
                drawOverlay();
            });
            document.getElementById('f-dark-mode').addEventListener('change', (e) => {
                setBaseLayer(e.target.checked ? 'dark' : 'light');
            });
            document.getElementById('f-step').addEventListener('input', (e) => {
                currentStepIx = parseInt(e.target.value, 10) || 0;
                if (manifest && manifest.steps_hours) {
                    document.getElementById('wm-step-label').innerHTML =
                        fmtStepLabel(manifest.steps_hours[currentStepIx]);
                    drawOverlay();
                }
            });

            // Premier rendu
            refreshHealth();
            loadManifest();
            setInterval(refreshHealth, 60_000);
        })();
    </script>
    @endpush
</x-app-shell>
