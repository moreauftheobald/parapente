<x-app-shell
    title="Carte des modèles météo"
    page-title="Carte des modèles"
    main-class="overflow-hidden">

    @push('styles')
    <style>
        /* Conteneur carte plein hauteur de la zone main.
           Pattern aligné sur map/_partials/styles/base.blade.php :
           width/height 100%, position:relative, et flex column pour
           empiler toolbar + carte. PAS de position:absolute (le <main>
           du shell n'est pas positionné, donc inset:0 remonterait à
           app-shell-root et couvrirait la navbar). */
        #mg-root {
            width: 100%; height: 100%;
            min-width: 0; min-height: 0;
            position: relative;
            display: flex; flex-direction: column;
            background: #0b1220;
            color: #cbd5e1;
            font-family: 'DM Sans', system-ui, sans-serif;
        }

        /* Toolbar fixe en haut */
        #mg-toolbar {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 12px; padding: 10px 14px;
            background: #0f172a;
            border-bottom: 1px solid #1e293b;
            z-index: 50;
            flex: 0 0 auto;
        }
        #mg-toolbar label.field {
            display: flex; flex-direction: column; gap: 2px;
            font-size: 10px; text-transform: uppercase;
            letter-spacing: 0.05em; color: #64748b;
        }
        #mg-toolbar select, #mg-toolbar input[type="checkbox"] {
            background: #1e293b; color: #e2e8f0;
            border: 1px solid #334155;
            border-radius: 6px;
            font-size: 12px; padding: 4px 8px;
        }
        #mg-toolbar select:focus { outline: none; border-color: #0ea5e9; }
        #mg-toolbar .chk {
            flex-direction: row; align-items: center; gap: 6px;
            color: #cbd5e1; cursor: pointer;
        }
        #mg-status {
            margin-left: auto;
            display: flex; gap: 16px; align-items: center;
            font-family: 'DM Mono', monospace;
            font-size: 11px; color: #94a3b8;
        }
        #mg-status .pill {
            padding: 2px 8px; border-radius: 999px;
            background: #1e293b; border: 1px solid #334155;
        }
        #mg-status .pill.warn { background: #422006; border-color: #92400e; color: #fbbf24; }

        /* La carte — flex enfant qui prend tout l'espace restant.
           min-height:0 est crucial pour que flex:1 fonctionne dans
           un parent flex column (sinon les enfants imposent leur
           hauteur naturelle et débordent). */
        #mg-map {
            flex: 1 1 auto;
            min-height: 0;
            position: relative;
            background: #020617;
        }

        /* Légende — injectée comme L.Control par Leaflet, donc dans
           leaflet-control-container.bottomleft. Pas de position
           absolute custom : Leaflet gère le placement. */
        .mg-legend {
            background: rgba(15,23,42,0.95);
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 11px;
            color: #cbd5e1;
            box-shadow: 0 4px 16px rgba(0,0,0,.4);
            max-width: 280px;
            pointer-events: auto;
        }
        .mg-legend h4 {
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em;
            color: #64748b; margin: 0 0 6px 0;
        }
        .mg-legend .row {
            display: flex; align-items: center; gap: 8px;
            margin: 2px 0;
        }
        .mg-legend .sw {
            width: 14px; height: 14px; border-radius: 3px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .mg-legend .ic { width: 18px; text-align: center; }
        .mg-legend .sep {
            height: 1px; background: #1e293b; margin: 8px 0;
        }

        /* Popup Leaflet */
        .leaflet-popup-content-wrapper {
            background: #0f172a !important;
            color: #e2e8f0 !important;
            border-radius: 8px !important;
            box-shadow: 0 4px 24px rgba(0,0,0,.6) !important;
            border: 1px solid #1e293b;
        }
        .leaflet-popup-tip { background: #0f172a !important; }
        .leaflet-popup-content { margin: 10px 12px !important; font-size: 12px; }
        .leaflet-popup-content h5 {
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 0.05em; color: #94a3b8;
            margin: 0 0 4px 0;
        }
        .leaflet-popup-content table {
            font-family: 'DM Mono', monospace; font-size: 11px;
            margin-top: 4px;
        }
        .leaflet-popup-content td.k { color: #64748b; padding-right: 8px; }
        .leaflet-popup-content td.v { color: #e2e8f0; text-align: right; }

        /* DivIcon container (au centre des cellules occupées) */
        .mg-cell-icon {
            display: flex; align-items: center; justify-content: center;
            pointer-events: none;
        }

        /* Contrôles Leaflet : reprend l'esprit dark de la carte météo */
        .leaflet-control-zoom a {
            background: #1e293b !important; color: #94a3b8 !important;
            border-color: #334155 !important;
        }
        .leaflet-control-zoom a:hover { background: #334155 !important; color: white !important; }
        .leaflet-bar { border-color: #334155 !important; box-shadow: 0 2px 8px rgba(0,0,0,.4) !important; }
    </style>
    @endpush

    <div id="mg-root">

        {{-- Toolbar --}}
        <div id="mg-toolbar">
            <label class="field">
                Modèle
                <select id="f-model">
                    @foreach ($models as $m)
                        <option value="{{ $m->id }}" data-resolution-km="{{ $m->resolution_km }}">
                            {{ $m->name }} ({{ $m->resolution_km }} km)
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                Variable
                <select id="f-variable">
                    @foreach ($variables as $k => $v)
                        <option value="{{ $k }}">{{ $v }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                Horizon
                <select id="f-bucket">
                    @foreach ($buckets as $k => $v)
                        <option value="{{ $k }}">{{ $v }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                Métrique
                <select id="f-metric">
                    @foreach ($metrics as $k => $v)
                        <option value="{{ $k }}">{{ $v }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field chk">
                <input type="checkbox" id="f-show-empty" checked>
                Afficher la grille complète
            </label>

            <div id="mg-status">
                <span class="pill" id="s-cells">— cellules</span>
                <span class="pill" id="s-occupied">— occupées</span>
                <span class="pill" id="s-zoom">zoom —</span>
                <span class="pill warn" id="s-warn" style="display:none">Zoom davantage</span>
            </div>
        </div>

        {{-- Carte (la légende est ajoutée comme L.Control dynamique) --}}
        <div id="mg-map"></div>
    </div>

    @push('scripts')
    <script>
        (function init() {
            // window.L est exposé par resources/js/app.js (chargé via Vite
            // au début du shell). En cas de race rare où ce script
            // s'exécute avant le bundle, on attend.
            if (typeof window.L === 'undefined') {
                setTimeout(init, 50);
                return;
            }
            const apiUrl = @json(route('model-grid.data'));

            // ── Palettes ──────────────────────────────────────────
            const PAL_WF = [
                {max: 0.5,  color: '#dc2626', label: '< 0.5 (médiocre)'},
                {max: 0.9,  color: '#f59e0b', label: '0.5 – 0.9'},
                {max: 1.1,  color: '#6b7280', label: '0.9 – 1.1 (médian)'},
                {max: 1.5,  color: '#0ea5e9', label: '1.1 – 1.5'},
                {max: 99,   color: '#10b981', label: '> 1.5 (excellent)'},
            ];
            // MAE : seuils différents par variable. linéaires (km/h) ou angulaire (°).
            const PAL_MAE_LIN = [
                {max: 2,  color: '#10b981', label: '< 2'},
                {max: 4,  color: '#0ea5e9', label: '2 – 4'},
                {max: 6,  color: '#6b7280', label: '4 – 6'},
                {max: 8,  color: '#f59e0b', label: '6 – 8'},
                {max: 999,color: '#dc2626', label: '> 8'},
            ];
            const PAL_MAE_DIR = [
                {max: 15, color: '#10b981', label: '< 15°'},
                {max: 30, color: '#0ea5e9', label: '15 – 30°'},
                {max: 45, color: '#6b7280', label: '30 – 45°'},
                {max: 60, color: '#f59e0b', label: '45 – 60°'},
                {max: 999,color: '#dc2626', label: '> 60°'},
            ];

            function paletteFor(metric, variable) {
                if (metric === 'weight_factor') return PAL_WF;
                if (metric === 'mae' && variable === 'wind_direction') return PAL_MAE_DIR;
                return PAL_MAE_LIN;
            }

            function colorFor(value, metric, variable) {
                if (value === null || value === undefined) return null;
                const palette = paletteFor(metric, variable);
                for (const p of palette) {
                    if (value <= p.max) return p.color;
                }
                return palette[palette.length - 1].color;
            }

            // ── Carte Leaflet ─────────────────────────────────────
            const map = L.map('mg-map', {
                preferCanvas: true,
                zoomControl: true,
                attributionControl: false,
            }).setView([46.5, 2.5], 6); // centre France

            L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                maxZoom: 18,
                subdomains: 'abcd',
            }).addTo(map);

            // Force le recalcul des dimensions au cas où la mesure
            // initiale a été prise sur un conteneur en cours de layout.
            // (Si layout flex pas encore résolu, le map peut s'init à 0 px
            // et rester noir.)
            setTimeout(() => map.invalidateSize(), 0);
            window.addEventListener('resize', () => map.invalidateSize());

            // ── Légende (L.Control plutôt que div sibling pour
            //    éviter les soucis de z-index avec les panes Leaflet) ─
            const LegendControl = L.Control.extend({
                options: { position: 'bottomleft' },
                onAdd: function () {
                    this._div = L.DomUtil.create('div', 'mg-legend');
                    // Empêche les interactions souris (drag/zoom) de la
                    // carte quand on est sur la légende.
                    L.DomEvent.disableClickPropagation(this._div);
                    L.DomEvent.disableScrollPropagation(this._div);
                    return this._div;
                },
                update: function (html) {
                    if (this._div) this._div.innerHTML = html;
                },
            });
            const legendCtrl = new LegendControl();
            legendCtrl.addTo(map);

            // Couche grille
            let gridLayer = L.layerGroup().addTo(map);
            // Couche markers (icônes bias / samples au centre des cellules)
            let markerLayer = L.layerGroup().addTo(map);

            // ── DivIcon : rond + flèche bias ──────────────────────
            function buildIcon(samplesN, biasSigned, variable) {
                const minSamples = 50;
                const okSamples  = samplesN >= minSamples;
                const dotColor = okSamples ? '#10b981' : '#94a3b8';

                // Seuil bias selon variable (en unités natives)
                const biasThreshold = variable === 'wind_direction' ? 5 : 0.5;
                let arrowSvg = '';
                if (biasSigned !== null && biasSigned !== undefined) {
                    if (biasSigned > biasThreshold) {
                        // Sur-estimation : flèche montante rouge
                        arrowSvg = '<path d="M14 4 L18 9 L15.5 9 L15.5 14 L12.5 14 L12.5 9 L10 9 Z" fill="#ef4444"/>';
                    } else if (biasSigned < -biasThreshold) {
                        // Sous-estimation : flèche descendante bleue
                        arrowSvg = '<path d="M14 14 L18 9 L15.5 9 L15.5 4 L12.5 4 L12.5 9 L10 9 Z" fill="#3b82f6"/>';
                    }
                    // sinon : pas de flèche (bias négligeable)
                }

                const svg = `
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="18" viewBox="0 0 22 18">
                        <circle cx="5" cy="9" r="4" fill="${dotColor}" stroke="#0f172a" stroke-width="1"/>
                        ${arrowSvg}
                    </svg>`;

                return L.divIcon({
                    className: 'mg-cell-icon',
                    html: svg,
                    iconSize: [22, 18],
                    iconAnchor: [11, 9],
                });
            }

            // ── Tooltip / popup d'une cellule ─────────────────────
            function buildPopup(props, variable) {
                if (!props.is_occupied) {
                    return '<em style="color:#64748b">Cellule sans balise dans le panel</em>';
                }
                const fmt = v => (v === null || v === undefined) ? '—' : (Math.round(v*100)/100).toString();
                const balises = (props.balise_names || []).join(', ');
                return `
                    <h5>Cellule de la grille</h5>
                    <div style="color:#cbd5e1; font-size:11px;">
                        ${props.balise_count} balise${props.balise_count>1?'s':''} :
                        <span style="color:#e2e8f0">${balises || '—'}</span>
                    </div>
                    <table style="width:100%; border-spacing:0; margin-top:6px">
                        <tr><td class="k">weight_factor</td><td class="v">${fmt(props.weight_factor)}</td></tr>
                        <tr><td class="k">MAE</td><td class="v">${fmt(props.mae)}</td></tr>
                        <tr><td class="k">bias signé</td><td class="v">${fmt(props.bias_signed)}</td></tr>
                        <tr><td class="k">samples_n</td><td class="v">${props.samples_n || 0}</td></tr>
                    </table>
                `;
            }

            // ── Rendu de la légende ───────────────────────────────
            function renderLegend(metric, variable) {
                const palette = paletteFor(metric, variable);
                const unit = (metric === 'mae')
                    ? (variable === 'wind_direction' ? '°' : 'km/h')
                    : '';
                let html = `<h4>${metric === 'weight_factor' ? 'weight_factor' : 'MAE (' + unit + ')'}</h4>`;
                palette.forEach(p => {
                    html += `<div class="row"><span class="sw" style="background:${p.color}"></span>${p.label}</div>`;
                });
                html += '<div class="sep"></div><h4>Icône centrale</h4>';
                html += `<div class="row"><span class="ic">●</span> rond vert = samples_n ≥ 50</div>`;
                html += `<div class="row"><span class="ic">●</span> rond gris = cold start (< 50)</div>`;
                html += `<div class="row"><span class="ic" style="color:#ef4444">▲</span> bias positif (sur-estime)</div>`;
                html += `<div class="row"><span class="ic" style="color:#3b82f6">▼</span> bias négatif (sous-estime)</div>`;
                legendCtrl.update(html);
            }

            // ── Fetch + rendu ─────────────────────────────────────
            let fetchSeq = 0;
            async function refresh() {
                const modelId  = +document.getElementById('f-model').value;
                const variable = document.getElementById('f-variable').value;
                const bucket   = document.getElementById('f-bucket').value;
                const metric   = document.getElementById('f-metric').value;
                const showEmpty = document.getElementById('f-show-empty').checked ? 1 : 0;
                const bbox = map.getBounds();

                const params = new URLSearchParams({
                    model_id:   modelId,
                    variable,
                    bucket,
                    south:      bbox.getSouth().toFixed(4),
                    west:       bbox.getWest().toFixed(4),
                    north:      bbox.getNorth().toFixed(4),
                    east:       bbox.getEast().toFixed(4),
                    show_empty: showEmpty,
                });

                renderLegend(metric, variable);
                document.getElementById('s-zoom').textContent = `zoom ${map.getZoom()}`;

                const seq = ++fetchSeq;
                let resp;
                try {
                    resp = await fetch(`${apiUrl}?${params.toString()}`, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                } catch (e) {
                    console.warn('model-grid fetch failed', e);
                    return;
                }
                if (seq !== fetchSeq) return; // une requête plus récente a pris la main
                if (!resp.ok) {
                    console.warn('model-grid HTTP', resp.status);
                    return;
                }
                const geo = await resp.json();
                if (seq !== fetchSeq) return;

                gridLayer.clearLayers();
                markerLayer.clearLayers();

                // Bandeau de status
                const meta = geo.metadata || {};
                document.getElementById('s-cells').textContent =
                    `${meta.cells_total ?? 0} cellules`;
                document.getElementById('s-occupied').textContent =
                    `${meta.cells_occupied ?? 0} occupées`;

                if (meta.too_large) {
                    const warn = document.getElementById('s-warn');
                    warn.textContent = `Zoom min recommandé : ${meta.recommended_min_zoom}`;
                    warn.style.display = '';
                    return;
                } else {
                    document.getElementById('s-warn').style.display = 'none';
                }

                // Polygones
                const layer = L.geoJSON(geo, {
                    style: (feat) => {
                        const p = feat.properties || {};
                        if (!p.is_occupied) {
                            return {
                                color: '#1e293b',
                                weight: 0.5,
                                fillColor: '#0b1220',
                                fillOpacity: 0.15,
                            };
                        }
                        const value = p[metric];
                        const fill  = colorFor(value, metric, variable) || '#374151';
                        return {
                            color: fill,
                            weight: 1.2,
                            opacity: 0.7,
                            fillColor: fill,
                            fillOpacity: 0.45,
                        };
                    },
                    onEachFeature: (feat, lyr) => {
                        lyr.bindPopup(buildPopup(feat.properties || {}, variable));
                    },
                });
                gridLayer.addLayer(layer);

                // Markers icône (uniquement cellules occupées)
                geo.features.forEach(feat => {
                    const p = feat.properties || {};
                    if (!p.is_occupied || (p.samples_n || 0) === 0) return;
                    const coords = feat.geometry.coordinates[0];
                    // bbox du polygone : centre = moyenne des 4 coins
                    const cx = (coords[0][0] + coords[2][0]) / 2;
                    const cy = (coords[0][1] + coords[2][1]) / 2;
                    L.marker([cy, cx], {
                        icon: buildIcon(p.samples_n, p.bias_signed, variable),
                        interactive: false,
                    }).addTo(markerLayer);
                });

                // Ajuste le zoomMin du modèle (informatif)
                const minZoom = meta.recommended_min_zoom;
                if (minZoom && map.getZoom() < minZoom) {
                    document.getElementById('s-warn').textContent =
                        `Zoom recommandé ≥ ${minZoom} pour ce modèle`;
                    document.getElementById('s-warn').style.display = '';
                }
            }

            // ── Debounce sur le moveend ───────────────────────────
            let moveTimer = null;
            map.on('moveend', () => {
                clearTimeout(moveTimer);
                moveTimer = setTimeout(refresh, 250);
            });

            // ── Listeners filtres ─────────────────────────────────
            ['f-model','f-variable','f-bucket','f-metric','f-show-empty'].forEach(id => {
                document.getElementById(id).addEventListener('change', refresh);
            });

            // Premier rendu
            refresh();
        })();
    </script>
    @endpush
</x-app-shell>
