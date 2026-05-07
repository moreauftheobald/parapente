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
    const BASE_Y = 188;
    const WIND_H = 70; // px for max wind

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

    // ── Labels section nuages ────────────────────────────────
    txt(10, 12, 'Couverture nuageuse', 10, '#e5e7eb');
    txt(W,  12, '▲haute  ▬moy.  ▼basse', 9, '#cbd5e1', 'end');

    // ── Nuages (3 tuiles par heure) ──────────────────────────
    dayData.forEach((h, i) => {
        const x = 10 + i * PITCH;
        [[17, h.cloud_high], [29, h.cloud_mid], [41, h.cloud_low]].forEach(([ty, pct]) => {
            mk('rect', {x, y:ty, width:COL_W, height:9, rx:2, fill:'#0d1b26'});
            // Opacité inversée : ciel bleu = dégagé (100% → opaque), sombre = couvert (0% → transparent)
            const op = pct != null ? ((100 - pct) / 100).toFixed(2) : '1.00';
            mk('rect', {x, y:ty, width:COL_W, height:9, rx:2, fill:'#4b8db5', 'fill-opacity': op});
        });
    });
    // Labels heures nuages — toutes les 2h
    const midI = Math.floor(N/2);
    for (let i = 0; i < N; i += 2) {
        const x = 10 + i * PITCH + (i === N-1 ? COL_W/2 : 0);
        const anchor = i === 0 ? null : (i === N-1 ? 'end' : 'middle');
        txt(x, 60, dayData[i]?.hour?.slice(0,2)+'h', 9, '#cbd5e1', anchor);
    }

    // ── Séparateur ───────────────────────────────────────────
    mk('line', {x1:10, y1:67, x2:W-10, y2:67, stroke:'#1f2937', 'stroke-width':1});

    // ── Titre vent ───────────────────────────────────────────
    txt(10, 79, 'Vent km/h', 10, '#e5e7eb');
    // Légende vent
    [[W-110,10,'#3b82f6','Min'],[W-70,10,'#22c55e','Moy'],[W-30,10,'#f97316','Max']].forEach(([lx,s,c,lb]) => {
        mk('rect',{x:lx-s,y:72,width:7,height:7,rx:1,fill:c});
        txt(lx-s+9, 79, lb, 9, '#cbd5e1');
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
        txt(6, y+3, v, 9, '#cbd5e1', 'end');
    });

    dayData.forEach((h, i) => {
        const x   = 10 + i * PITCH;
        const fav = h.status === 'green' || h.status === 'orange';

        if (h.wind_max != null) {
            const mxH = Math.max(1, h.wind_max * scale);
            mk('rect',{x, y:BASE_Y-mxH, width:COL_W, height:mxH, rx:2,
                fill: fav ? 'rgba(249,115,22,.2)' : 'rgba(75,85,99,.2)'});
        }
        if (h.wind_avg != null) {
            const avH = Math.max(1, h.wind_avg * scale);
            const aw  = Math.max(4, Math.floor(COL_W * 0.65));
            const ax  = x + Math.floor((COL_W - aw) / 2);
            mk('rect',{x:ax, y:BASE_Y-avH, width:aw, height:avH, rx:2,
                fill: fav ? '#16a34a' : '#374151'});
        }
        if (h.wind_min != null) {
            const mnH = Math.max(1, h.wind_min * scale);
            const mw  = Math.max(2, Math.floor(COL_W * 0.35));
            const mx2 = x + Math.floor((COL_W - mw) / 2);
            mk('rect',{x:mx2, y:BASE_Y-mnH, width:mw, height:mnH, rx:2,
                fill: fav ? '#3b82f6' : '#1e3a5f'});
        }

        // ── Flèche direction ──────────────────────────────
        if (h.wind_dir != null) {
            const arrowColor = dirFav(h.wind_dir) ? '#22c55e' : '#ef4444';
            const cx = x + COL_W/2;
            const cy = BASE_Y + 14;
            const g  = mk('g', {transform:`translate(${cx},${cy}) rotate(${(h.wind_dir + 180) % 360})`});
            mk('polygon', {points:'0,-7 4,3 0,1 -4,3', fill:arrowColor}, g);
        }
    });

    // Labels heures vent — toutes les 2h
    for (let i = 0; i < N; i += 2) {
        const x = 10 + i * PITCH + (i === N-1 ? COL_W/2 : 0);
        const anchor = i === 0 ? null : (i === N-1 ? 'end' : 'middle');
        txt(x, BASE_Y+30, dayData[i]?.hour?.slice(0,2)+'h', 9, '#cbd5e1', anchor);
    }
}
