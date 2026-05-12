// ── Génération du SVG du popup ───────────────────────────────
// Distinct des graphes du panel (logique différente : tuiles
// nuageuses + 3 barres vent par heure + flèche direction).
function buildChartSVG(dayData, siteInfo) {
    const svgEl = document.getElementById('chart-svg');
    if (!svgEl || !dayData || !dayData.length) return;
    while (svgEl.firstChild) svgEl.removeChild(svgEl.firstChild);

    const N      = dayData.length;
    const W      = 428;
    const PITCH  = Math.floor((W - 20) / N);
    const COL_W  = PITCH - 2;

    // ── Layout vertical (compact) ────────────────────────────
    const CLOUD_TOP   = [15, 23, 31];   // y des 3 tuiles : haute / moyenne / basse
    const TILE_H      = 7;              // hauteur d'une tuile (réduite)
    const CLOUD_HRS_Y = 46;            // labels heures sous les tuiles
    const SEP_Y       = 52;            // séparateur nuages / vent
    const WIND_LBL_Y  = 62;            // titre + légende du graphe vent
    const BASE_Y      = 168;           // ligne de base des barres vent
    const WIND_H      = 66;            // px pour le vent max
    const ARROW_Y     = BASE_Y + 13;   // flèches de direction
    const WIND_HRS_Y  = BASE_Y + 27;   // labels heures sous les barres

    const FS_LBL = 8;    // libellés de section
    const FS_SM  = 6.5;  // petits libellés (heures, échelle, légende)

    // Échelle vent dynamique
    const maxWind = Math.max(...dayData.map(d => d.wind_max || 0)) || 30;
    const scale   = WIND_H / maxWind;

    function mk(tag, attrs, parent) {
        const e = document.createElementNS(NS, tag);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
        (parent || svgEl).appendChild(e);
        return e;
    }
    function txt(x, y, s, sz, fill, anchor) {
        const t = mk('text', {x, y, 'font-family':'DM Mono,monospace', 'font-size':sz, fill, ...(anchor?{'text-anchor':anchor}:{})});
        t.textContent = s;
    }

    // ── Couverture nuageuse ──────────────────────────────────
    txt(10, 10, 'Couverture nuageuse', FS_LBL, '#e5e7eb');
    txt(W,  10, '▲haute  ▬moy.  ▼basse', FS_SM, '#cbd5e1', 'end');

    dayData.forEach((h, i) => {
        const x = 10 + i * PITCH;
        [[CLOUD_TOP[0], h.cloud_high], [CLOUD_TOP[1], h.cloud_mid], [CLOUD_TOP[2], h.cloud_low]].forEach(([ty, pct]) => {
            mk('rect', {x, y:ty, width:COL_W, height:TILE_H, rx:1.5, fill:'#0d1b26'});
            // Opacité inversée : ciel bleu = dégagé (100% → opaque), sombre = couvert (0% → transparent)
            const op = pct != null ? ((100 - pct) / 100).toFixed(2) : '1.00';
            mk('rect', {x, y:ty, width:COL_W, height:TILE_H, rx:1.5, fill:'#4b8db5', 'fill-opacity': op});
        });
    });
    for (let i = 0; i < N; i += 2) {
        const x = 10 + i * PITCH + (i === N-1 ? COL_W/2 : 0);
        const anchor = i === 0 ? null : (i === N-1 ? 'end' : 'middle');
        txt(x, CLOUD_HRS_Y, dayData[i]?.hour?.slice(0,2)+'h', FS_SM, '#cbd5e1', anchor);
    }

    // ── Séparateur ───────────────────────────────────────────
    mk('line', {x1:10, y1:SEP_Y, x2:W-10, y2:SEP_Y, stroke:'#1f2937', 'stroke-width':1});

    // ── Titre + légende vent ─────────────────────────────────
    txt(10, WIND_LBL_Y, 'Vent km/h', FS_LBL, '#e5e7eb');
    [[W-104,'#3b82f6','Min'],[W-66,'#22c55e','Moy'],[W-28,'#f97316','Max']].forEach(([lx,c,lb]) => {
        mk('rect',{x:lx-8,y:WIND_LBL_Y-7,width:6,height:6,rx:1,fill:c});
        txt(lx, WIND_LBL_Y, lb, FS_SM, '#cbd5e1');
    });

    // Ligne de base vent
    mk('line',{x1:10,y1:BASE_Y,x2:W-10,y2:BASE_Y,stroke:'#1f2937','stroke-width':1});

    // ── Barres vent ──────────────────────────────────────────
    const condMin = siteInfo?.wind_dir_min ?? 0;
    const condMax = siteInfo?.wind_dir_max ?? 360;

    function dirFav(dir) {
        if (dir == null) return false;
        if (condMin <= condMax) return dir >= condMin && dir <= condMax;
        return dir >= condMin || dir <= condMax; // chevauche Nord
    }

    // Échelle
    [0, Math.round(maxWind/2), Math.round(maxWind)].forEach(v => {
        const y = BASE_Y - v * scale;
        mk('line',{x1:7,y1:y,x2:9,y2:y,stroke:'#6b7280','stroke-width':1});
        txt(6, y+3, v, FS_SM, '#cbd5e1', 'end');
    });

    dayData.forEach((h, i) => {
        const x = 10 + i * PITCH;

        // Couleurs alignées sur la légende : Max=orange, Moy=vert, Min=bleu
        if (h.wind_max != null) {
            const mxH = Math.max(1, h.wind_max * scale);
            mk('rect',{x, y:BASE_Y-mxH, width:COL_W, height:mxH, rx:2, fill:'rgba(249,115,22,.22)'});
        }
        if (h.wind_avg != null) {
            const avH = Math.max(1, h.wind_avg * scale);
            const aw  = Math.max(4, Math.floor(COL_W * 0.65));
            const ax  = x + Math.floor((COL_W - aw) / 2);
            mk('rect',{x:ax, y:BASE_Y-avH, width:aw, height:avH, rx:2, fill:'#22c55e'});
        }
        if (h.wind_min != null) {
            const mnH = Math.max(1, h.wind_min * scale);
            const mw  = Math.max(2, Math.floor(COL_W * 0.35));
            const mx2 = x + Math.floor((COL_W - mw) / 2);
            mk('rect',{x:mx2, y:BASE_Y-mnH, width:mw, height:mnH, rx:2, fill:'#3b82f6'});
        }

        // ── Flèche direction ──────────────────────────────
        if (h.wind_dir != null) {
            const arrowColor = dirFav(h.wind_dir) ? '#22c55e' : '#ef4444';
            const cx = x + COL_W/2;
            const g  = mk('g', {transform:`translate(${cx},${ARROW_Y}) rotate(${(h.wind_dir + 180) % 360})`});
            mk('polygon', {points:'0,-7 4,3 0,1 -4,3', fill:arrowColor}, g);
        }
    });

    // Labels heures vent — toutes les 2h
    for (let i = 0; i < N; i += 2) {
        const x = 10 + i * PITCH + (i === N-1 ? COL_W/2 : 0);
        const anchor = i === 0 ? null : (i === N-1 ? 'end' : 'middle');
        txt(x, WIND_HRS_Y, dayData[i]?.hour?.slice(0,2)+'h', FS_SM, '#cbd5e1', anchor);
    }
}

// ── Bargraph « plafond de vol estimé » ──────────────────────
// 3 barres centrées par heure (comme le graphe de vent) :
//   max modèles  (large, translucide)  /  consensus (médiane, plein)  /
//   min modèles  (étroite, foncée).
// Repère pointillé à l'altitude du décollage ; barre consensus rouge
// si le plafond passe sous cette altitude. Plafonds null (ciel
// dégagé) ⇒ tiret « — ».
function buildCeilingSVG(dayData, siteInfo) {
    const svgEl = document.getElementById('ceiling-svg');
    if (!svgEl) return;
    while (svgEl.firstChild) svgEl.removeChild(svgEl.firstChild);
    if (!dayData || !dayData.length) return;

    const N      = dayData.length;
    const W      = 428;
    const PITCH  = Math.floor((W - 20) / N);
    const COL_W  = PITCH - 2;
    const TOP_Y  = 18, BASE_Y = 92, BAR_H = BASE_Y - TOP_Y;
    const FS_LBL = 8, FS_SM = 6.5;

    const vals     = dayData.flatMap(d => [d.cloud_base, d.cloud_base_min, d.cloud_base_max]).filter(v => v != null);
    const maxC     = vals.length ? Math.max(...vals) : 2000;
    const scaleMax = Math.max(500, Math.ceil(maxC / 500) * 500);
    const scale    = BAR_H / scaleMax;
    const alt      = siteInfo?.altitude ?? null;

    // Même charte graphique que le graphe de vent : Max orange translucide,
    // Moy (= consensus) vert, Min bleu.
    const C_MAX = 'rgba(249,115,22,.22)';  // max modèles
    const C_CON = '#22c55e';               // consensus (« moy »)
    const C_MIN = '#3b82f6';               // min modèles
    const C_LOW = 'rgba(239,68,68,.8)';    // consensus sous le décollage

    function mk(tag, attrs, parent) {
        const e = document.createElementNS(NS, tag);
        for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
        (parent || svgEl).appendChild(e);
        return e;
    }
    function txt(x, y, s, sz, fill, anchor) {
        const t = mk('text', {x, y, 'font-family':'DM Mono,monospace', 'font-size':sz, fill, ...(anchor?{'text-anchor':anchor}:{})});
        t.textContent = s;
    }
    function barTop(v) { return BASE_Y - Math.max(1, Math.min(v, scaleMax) * scale); }

    // Titre + légende
    txt(10, 10, 'Plafond de vol estimé (m)', FS_LBL, '#e5e7eb');
    [[W-110,C_MIN,'Min'],[W-70,C_CON,'Moy'],[W-30,C_MAX,'Max']].forEach(([lx,c,lb]) => {
        mk('rect',{x:lx-8,y:5,width:6,height:6,rx:1,fill:c});
        txt(lx, 10, lb, FS_SM, '#cbd5e1');
    });

    // Échelle Y
    [0, Math.round(scaleMax / 2), scaleMax].forEach(v => {
        const y = BASE_Y - v * scale;
        mk('line', {x1:10, y1:y, x2:W-10, y2:y, stroke:'#1f2937', 'stroke-width':1, 'stroke-dasharray':'2,3'});
        txt(8, y+3, v, FS_SM, '#cbd5e1', 'end');
    });
    mk('line', {x1:10, y1:BASE_Y, x2:W-10, y2:BASE_Y, stroke:'#1f2937', 'stroke-width':1});

    // Repère altitude du décollage
    if (alt != null && alt > 0 && alt <= scaleMax) {
        const y = BASE_Y - alt * scale;
        mk('line', {x1:10, y1:y, x2:W-10, y2:y, stroke:'#fbbf24', 'stroke-width':1, 'stroke-dasharray':'4,3', 'stroke-opacity':.75});
        txt(W-12, y-3, 'décollage '+alt+' m', FS_SM, '#fbbf24', 'end');
    }

    // Barres : max (large) → consensus (médiane) → min (étroite)
    dayData.forEach((h, i) => {
        const x = 10 + i * PITCH;
        if (h.cloud_base == null && h.cloud_base_max == null && h.cloud_base_min == null) {
            txt(x + COL_W/2, TOP_Y - 2, '—', FS_SM, '#475569', 'middle');
            return;
        }
        if (h.cloud_base_max != null) {
            const y = barTop(h.cloud_base_max);
            mk('rect', {x, y, width:COL_W, height:BASE_Y-y, rx:2, fill:C_MAX});
        }
        if (h.cloud_base != null) {
            const y   = barTop(h.cloud_base);
            const aw  = Math.max(4, Math.floor(COL_W * 0.65));
            const ax  = x + Math.floor((COL_W - aw) / 2);
            const low = alt != null && h.cloud_base < alt;
            mk('rect', {x:ax, y, width:aw, height:BASE_Y-y, rx:2, fill: low ? C_LOW : C_CON});
        }
        if (h.cloud_base_min != null) {
            const y  = barTop(h.cloud_base_min);
            const mw = Math.max(2, Math.floor(COL_W * 0.35));
            const mx = x + Math.floor((COL_W - mw) / 2);
            mk('rect', {x:mx, y, width:mw, height:BASE_Y-y, rx:2, fill:C_MIN});
        }
    });

    // Labels heures — toutes les 2h
    for (let i = 0; i < N; i += 2) {
        const x = 10 + i * PITCH + (i === N-1 ? COL_W/2 : 0);
        const anchor = i === 0 ? null : (i === N-1 ? 'end' : 'middle');
        txt(x, BASE_Y+12, dayData[i]?.hour?.slice(0,2)+'h', FS_SM, '#cbd5e1', anchor);
    }
}
