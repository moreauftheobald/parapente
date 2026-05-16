// ── Graphes de la popup balise ───────────────────────────────
//
// 1) buildBaliseRoseSVG  : cadran 8 branches (N..NO) ; une ligne dont
//    la couleur évolue heure par heure relie les points (angle =
//    direction d'OÙ VIENT le vent, rayon = vitesse moyenne).
// 2) buildBaliseChartSVG : timeline vitesse km/h (rafales/moyen/min) ;
//    le survol y déplace un rayon sur la rose des vents (heure pointée).

// Géométrie de la dernière rose dessinée (pour le survol depuis le graphe).
let _baliseRoseGeo = null;

// Couleur d'un point selon son rang horaire : bleu (matin) → ambre (soir).
function baliseHourColor(i, n) {
    const t = n <= 1 ? 0 : i / (n - 1);
    const c1 = [56, 189, 248], c2 = [245, 158, 11];
    const r = Math.round(c1[0] + (c2[0] - c1[0]) * t);
    const g = Math.round(c1[1] + (c2[1] - c1[1]) * t);
    const b = Math.round(c1[2] + (c2[2] - c1[2]) * t);
    return `rgb(${r},${g},${b})`;
}

function buildBaliseRoseSVG(data) {
    const svg = document.getElementById('balise-rose-svg');
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);
    _baliseRoseGeo = null;

    const readings = (data?.readings ?? []).filter(r => r.wind_direction != null);
    if (!readings.length) return;

    const SIZE = 320;
    svg.setAttribute('viewBox', `0 0 ${SIZE} ${SIZE}`);
    const cx = SIZE / 2, cy = SIZE / 2;
    const R = 122;      // rayon de l'anneau extérieur (= vitesse max)
    const RLAB = 146;   // rayon des labels cardinaux

    // Échelle vitesse → rayon
    let vmax = 0;
    readings.forEach(r => { const v = r.wind_speed_avg ?? 0; if (v > vmax) vmax = v; });
    vmax = Math.max(10, Math.ceil(vmax / 5) * 5);
    const rScale = v => Math.min(Math.max(v, 0), vmax) / vmax * R;
    // angle météo (0° = Nord = haut) → coordonnées écran
    const toXY = (dirDeg, r) => {
        const a = (dirDeg - 90) * Math.PI / 180;
        return [cx + r * Math.cos(a), cy + r * Math.sin(a)];
    };

    const { mk, txt } = svgHelpers(svg);

    // Anneaux concentriques (échelle vitesse)
    [vmax / 2, vmax].forEach(v => {
        mk('circle', { cx, cy, r: rScale(v).toFixed(1), fill: 'none', stroke: '#1f2937', 'stroke-width': 1 });
    });
    txt(cx + 3, cy - rScale(vmax) + 2, vmax + ' km/h', 7, '#6b7280', 'start', 'hanging');
    txt(cx + 3, cy - rScale(vmax / 2) + 2, (vmax / 2) + '', 7, '#6b7280', 'start', 'hanging');

    // 8 branches + labels cardinaux
    const LABELS = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
    for (let i = 0; i < 8; i++) {
        const dir = i * 45;
        const [x2, y2] = toXY(dir, R);
        mk('line', { x1: cx, y1: cy, x2: x2.toFixed(1), y2: y2.toFixed(1), stroke: '#1f2937', 'stroke-width': 1 });
        const [lx, ly] = toXY(dir, RLAB);
        txt(lx.toFixed(1), ly.toFixed(1), LABELS[i], 11, i === 0 ? '#e5e7eb' : '#9ca3af', 'middle', 'middle');
    }

    // Points horaires (rayon = vitesse moyenne, plancher pour les vents nuls)
    const N = readings.length;
    const pts = readings.map(r => {
        const speed = Math.max(r.wind_speed_avg ?? 0, vmax * 0.10);
        return toXY(r.wind_direction, rScale(speed));
    });

    // Ligne reliant heure par heure, segments colorés par rang horaire
    for (let i = 1; i < pts.length; i++) {
        mk('line', {
            x1: pts[i - 1][0].toFixed(1), y1: pts[i - 1][1].toFixed(1),
            x2: pts[i][0].toFixed(1), y2: pts[i][1].toFixed(1),
            stroke: baliseHourColor(i, N), 'stroke-width': 2.2, 'stroke-opacity': .8, 'stroke-linecap': 'round',
        });
    }
    // Points
    readings.forEach((r, i) => {
        mk('circle', { cx: pts[i][0].toFixed(1), cy: pts[i][1].toFixed(1), r: 2.8, fill: baliseHourColor(i, N) });
    });
    // Dernier relevé : point plus gros, liseré blanc
    const last = pts[N - 1];
    mk('circle', { cx: last[0].toFixed(1), cy: last[1].toFixed(1), r: 5, fill: baliseHourColor(N - 1, N), stroke: '#fff', 'stroke-width': 1.4 });

    // Calque de surbrillance (rayon piloté par le survol du graphe vitesse)
    mk('g', { id: 'rose-hl' });

    _baliseRoseGeo = { cx, cy, R, rScale, toXY, vmax };
}

function clearBaliseRoseHighlight() {
    const g = document.getElementById('rose-hl');
    if (g) while (g.firstChild) g.removeChild(g.firstChild);
}

// Dessine un rayon (centre → point de l'heure pointée) sur la rose.
function highlightBaliseRoseAt(reading) {
    clearBaliseRoseHighlight();
    const g = document.getElementById('rose-hl');
    if (!g || !_baliseRoseGeo || !reading || reading.wind_direction == null) return;

    const { cx, cy, rScale, toXY, vmax } = _baliseRoseGeo;
    const speed = Math.max(reading.wind_speed_avg ?? 0, vmax * 0.10);
    const [px, py] = toXY(reading.wind_direction, rScale(speed));

    // svgHelpers crée par défaut dans le svg racine — ici on veut tout
    // pousser dans le groupe `rose-hl`, donc on passe `g` comme parent.
    const { mk, txt } = svgHelpers(g);
    mk('line', { x1: cx, y1: cy, x2: px.toFixed(1), y2: py.toFixed(1), stroke: '#fff', 'stroke-width': 3, 'stroke-linecap': 'round' });
    mk('circle', { cx: px.toFixed(1), cy: py.toFixed(1), r: 5.5, fill: '#fff' });

    const compass = (typeof degToCompass === 'function') ? degToCompass(reading.wind_direction) : '';
    const label   = `${reading.time ?? ''} · ${Math.round(reading.wind_speed_avg ?? 0)} km/h · ${compass}`;
    const anchor  = px >= cx ? 'start' : 'end';
    const lx = px + (px >= cx ? 6 : -6);
    const ly = py + (py >= cy ? 12 : -5);
    txt(lx.toFixed(1), ly.toFixed(1), label, 9, '#fff', anchor);
}

function buildBaliseChartSVG(data) {
    const svg = document.getElementById('balise-chart-svg');
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const readings = (data?.readings ?? []).filter(r => r.min_of_day != null);
    if (!readings.length) return;

    const W = 440, H = 140;
    const PAD_L = 26, PAD_R = 8, PAD_T = 8, PAD_B = 20;
    const innerW = W - PAD_L - PAD_R;
    const innerH = H - PAD_T - PAD_B;
    const baseY  = PAD_T + innerH;

    // ── Échelle X (minutes du jour) ──────────────────────────
    let x0 = Math.min(...readings.map(r => r.min_of_day));
    let x1 = Math.max(...readings.map(r => r.min_of_day));
    if (x1 - x0 < 60) { x0 -= 60; x1 += 60; }
    else { x0 -= 15; x1 += 15; }
    x0 = Math.max(0, x0); x1 = Math.min(1440, x1);
    if (x1 <= x0) x1 = x0 + 60;
    const xScale = m => PAD_L + (m - x0) / (x1 - x0) * innerW;

    // ── Échelle Y (km/h) ─────────────────────────────────────
    let ymax = 0;
    readings.forEach(r => {
        const v = r.wind_speed_max ?? r.wind_speed_avg ?? 0;
        if (v > ymax) ymax = v;
    });
    ymax = Math.max(15, Math.ceil(ymax * 1.1));
    const yScale = v => baseY - Math.min(v, ymax) / ymax * innerH;

    const { mk, txt } = svgHelpers(svg);

    // ── Grille Y ─────────────────────────────────────────────
    [0, Math.round(ymax / 2), ymax].forEach(v => {
        const y = yScale(v);
        mk('line', { x1: PAD_L, y1: y, x2: W - PAD_R, y2: y, stroke: '#1f2937', 'stroke-width': 1 });
        txt(PAD_L - 4, y + 3, v, 6.5, '#9ca3af', 'end');
    });

    // ── Grille X (heures rondes) ─────────────────────────────
    const spanHours = (x1 - x0) / 60;
    const stepH = spanHours > 9 ? 3 : (spanHours > 5 ? 2 : 1);
    const firstH = Math.ceil(x0 / 60);
    for (let h = firstH; h * 60 <= x1; h++) {
        if (h % stepH !== 0) continue;
        const x = xScale(h * 60);
        mk('line', { x1: x, y1: PAD_T, x2: x, y2: baseY, stroke: '#1a2433', 'stroke-width': 1 });
        txt(x, baseY + 13, String(h).padStart(2, '0') + 'h', 6.5, '#9ca3af', 'middle');
    }
    mk('line', { x1: PAD_L, y1: baseY, x2: W - PAD_R, y2: baseY, stroke: '#374151', 'stroke-width': 1 });

    // ── Construction des séries (chemins) ────────────────────
    const pathFor = key => {
        let d = '';
        readings.forEach(r => {
            const v = r[key];
            if (v == null) return;
            const x = xScale(r.min_of_day).toFixed(1);
            const y = yScale(v).toFixed(1);
            d += (d === '' ? `M${x},${y}` : ` L${x},${y}`);
        });
        return d;
    };

    // Aire rafales (max)
    const maxPts = readings.filter(r => r.wind_speed_max != null);
    if (maxPts.length) {
        let d = `M${xScale(maxPts[0].min_of_day).toFixed(1)},${baseY}`;
        maxPts.forEach(r => { d += ` L${xScale(r.min_of_day).toFixed(1)},${yScale(r.wind_speed_max).toFixed(1)}`; });
        d += ` L${xScale(maxPts[maxPts.length - 1].min_of_day).toFixed(1)},${baseY} Z`;
        mk('path', { d, fill: '#f97316', 'fill-opacity': .16 });
        mk('path', { d: pathFor('wind_speed_max'), fill: 'none', stroke: '#f97316', 'stroke-width': 1, 'stroke-opacity': .55, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    }
    // Trait min
    const minD = pathFor('wind_speed_min');
    if (minD) mk('path', { d: minD, fill: 'none', stroke: '#3b82f6', 'stroke-width': 1.2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    // Trait moyen (par-dessus)
    const avgD = pathFor('wind_speed_avg');
    if (avgD) mk('path', { d: avgD, fill: 'none', stroke: '#22c55e', 'stroke-width': 1.8, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    // Points moyens
    readings.forEach(r => {
        if (r.wind_speed_avg == null) return;
        mk('circle', { cx: xScale(r.min_of_day).toFixed(1), cy: yScale(r.wind_speed_avg).toFixed(1), r: 1.6, fill: '#22c55e' });
    });

    // ── Curseur interactif → pilote le rayon de la rose des vents ─
    const cursorG = mk('g', {});
    const { mk: mkc } = svgHelpers(cursorG);
    const clearCursor = () => { while (cursorG.firstChild) cursorG.removeChild(cursorG.firstChild); };
    const overlay = mk('rect', { x: PAD_L, y: PAD_T, width: innerW, height: innerH, fill: 'transparent', 'pointer-events': 'all', style: 'cursor:crosshair;' });

    overlay.addEventListener('mousemove', (e) => {
        const rect   = svg.getBoundingClientRect();
        const scaleX = rect.width > 0 ? W / rect.width : 1;
        const localX = (e.clientX - rect.left) * scaleX;
        let best = null, bestD = Infinity;
        readings.forEach(r => { const d = Math.abs(xScale(r.min_of_day) - localX); if (d < bestD) { bestD = d; best = r; } });
        if (!best) return;
        clearCursor();
        const x = xScale(best.min_of_day);
        mkc('line', { x1: x, y1: PAD_T, x2: x, y2: baseY, stroke: 'rgba(255,255,255,.5)', 'stroke-width': 1, 'stroke-dasharray': '3,3' });
        if (best.wind_speed_avg != null) mkc('circle', { cx: x, cy: yScale(best.wind_speed_avg), r: 3, fill: '#fff' });
        highlightBaliseRoseAt(best);
    });
    overlay.addEventListener('mouseleave', () => { clearCursor(); clearBaliseRoseHighlight(); });
}
