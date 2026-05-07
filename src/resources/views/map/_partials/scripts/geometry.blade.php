// ── Helpers SVG partagés par tous les graphes du panel ───────
// COMPASS_TICKS est défini dans config.blade.php (déjà chargé).

function svgMk(svg, tag, attrs, parent) {
    const e = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
    (parent || svg).appendChild(e);
    return e;
}

function computeYDomain(cfg, data) {
    const vals = [];
    data.hours.forEach(h => {
        Object.values(data.data[h] || {}).forEach(m => {
            const v = m[cfg.key];
            if (v != null) vals.push(+v);
        });
        if (cfg.consensusKey) {
            const cv = data.consensus[h]?.[cfg.consensusKey];
            if (cv != null) vals.push(+cv);
        }
    });
    let yMin = cfg.yMin === 'auto' ? (vals.length ? Math.min(...vals) : 0) : cfg.yMin;
    let yMax = cfg.yMax === 'auto' ? (vals.length ? Math.max(...vals) : 1) : cfg.yMax;
    // Petite marge en haut/bas pour les axes auto
    if (cfg.yMin === 'auto') yMin = Math.floor(yMin - 1);
    if (cfg.yMax === 'auto') yMax = Math.ceil(yMax + 1);
    if (yMin === yMax) yMax = yMin + 1;
    return [yMin, yMax];
}

function formatTickValue(v) {
    if (Math.abs(v) >= 100) return Math.round(v).toString();
    if (Math.abs(v) >= 10)  return Math.round(v).toString();
    return (Math.round(v * 10) / 10).toString();
}

// Construit un path SVG depuis une liste de points (avec gestion des null
// et du wrap pour la direction du vent : si saut > 180° on interrompt).
function buildLinePath(points, wrap) {
    let d = '';
    let prev = null;
    for (const pt of points) {
        if (pt == null) { prev = null; continue; }
        if (prev == null || (wrap && Math.abs(pt.v - prev.v) > 180)) {
            d += `M ${pt.x.toFixed(1)} ${pt.y.toFixed(1)} `;
        } else {
            d += `L ${pt.x.toFixed(1)} ${pt.y.toFixed(1)} `;
        }
        prev = pt;
    }
    return d.trim();
}

// Bandeau jour/nuit + bandeau favorable (vitesse ou direction).
// En mode 5 jours (data.viewMode==='fivedays'), utilise data.sun_windows_by_day
// pour appliquer la bonne fenêtre solaire à chaque jour.
function drawBackgroundBands(svg, cfg, data, geo) {
    const { PAD_L, PAD_T, innerW, innerH, xScale, yScale, hours, N } = geo;
    const [yMin, yMax] = geo.yDomain;

    // Helper : retourne la fenêtre solaire pour une heure plate donnée
    const sunForHour = (h) => {
        if (data.viewMode === 'fivedays' && Array.isArray(data.sun_windows_by_day)) {
            const dayIdx = Math.floor(h / 24);
            return data.sun_windows_by_day[dayIdx] || null;
        }
        if (data.sun_window) return {start: data.sun_window.start_hour, end: data.sun_window.end_hour, offset: 0};
        return null;
    };

    // Bandeau "jour" (fenêtre solaire) — léger éclaircissement
    hours.forEach((h, i) => {
        const sw = sunForHour(h);
        if (!sw || sw.start == null) return;
        const hOfDay = h - (sw.offset ?? 0);
        if (hOfDay < sw.start || hOfDay > sw.end) return;
        const x1 = i === 0 ? PAD_L : (xScale(i-1) + xScale(i)) / 2;
        const x2 = i === N-1 ? PAD_L + innerW : (xScale(i) + xScale(i+1)) / 2;
        svgMk(svg, 'rect', {x:x1, y:PAD_T, width:Math.max(0,x2-x1), height:innerH, fill:'rgba(255,255,255,.06)'});
    });

    // Bandeau favorable
    if (cfg.favBand === 'speed') {
        const lo = data.site?.wind_speed_min, hi = data.site?.wind_speed_max;
        if (lo != null && hi != null) {
            const yA = yScale(Math.min(hi, yMax));
            const yB = yScale(Math.max(lo, yMin));
            if (yB > yA) svgMk(svg, 'rect', {x:PAD_L, y:yA, width:innerW, height:yB-yA, fill:'rgba(34,197,94,.08)'});
        }
    } else if (cfg.favBand === 'dir') {
        const lo = data.site?.wind_dir_min, hi = data.site?.wind_dir_max;
        if (lo != null && hi != null) {
            if (lo <= hi) {
                const yA = yScale(hi), yB = yScale(lo);
                svgMk(svg, 'rect', {x:PAD_L, y:yA, width:innerW, height:yB-yA, fill:'rgba(34,197,94,.08)'});
            } else {
                // Chevauche le Nord (ex: 340° → 30°) : 2 zones
                svgMk(svg, 'rect', {x:PAD_L, y:yScale(360), width:innerW, height:yScale(lo)-yScale(360), fill:'rgba(34,197,94,.08)'});
                svgMk(svg, 'rect', {x:PAD_L, y:yScale(hi),  width:innerW, height:yScale(0)-yScale(hi),   fill:'rgba(34,197,94,.08)'});
            }
        }
    }
}

function drawAxes(svg, cfg, geo, data) {
    const { PAD_L, PAD_T, innerW, innerH, xScale, yScale, hours, N, H } = geo;
    const [yMin, yMax] = geo.yDomain;

    // Grille horizontale + labels Y
    let ticks;
    if (cfg.yTicks === 'compass') {
        ticks = COMPASS_TICKS.map(c => ({value:c.deg, label:c.label}));
    } else {
        ticks = [yMin, (yMin + yMax) / 2, yMax].map(v => ({value:v, label:formatTickValue(v)}));
    }
    ticks.forEach(t => {
        const y = yScale(t.value);
        svgMk(svg, 'line', {x1:PAD_L, y1:y, x2:PAD_L+innerW, y2:y, stroke:'#1f2937', 'stroke-width':1, 'stroke-dasharray':'2,3'});
        const lbl = svgMk(svg, 'text', {x:PAD_L-5, y:y+3.5, 'text-anchor':'end', 'font-size':10, fill:'#cbd5e1', 'font-family':'DM Mono,monospace'});
        lbl.textContent = t.label;
    });

    // ── Axe X ─────────────────────────────────────────────────
    if (data && data.viewMode === 'fivedays') {
        // Séparateurs verticaux entre jours
        (data.day_separators || []).forEach(sep => {
            const idx = hours.indexOf(sep);
            if (idx < 0) return;
            const x = xScale(idx);
            svgMk(svg, 'line', {x1:x, y1:PAD_T, x2:x, y2:PAD_T+innerH, stroke:'rgba(255,255,255,.18)', 'stroke-width':1});
        });
        // Label centré sous chaque journée
        (data.day_labels || []).forEach(dl => {
            const startIdx = hours.indexOf(dl.offset);
            if (startIdx < 0) return;
            const midIdx = Math.min(N - 1, startIdx + 12);
            const x = xScale(midIdx);
            const t = svgMk(svg, 'text', {x, y:H-5, 'text-anchor':'middle', 'font-size':10, fill:'#cbd5e1', 'font-family':'DM Sans,sans-serif', 'font-weight':500});
            t.textContent = dl.label;
        });
    } else {
        // Mode 1 jour : labels heures toutes les 2h
        const labelIdx = [];
        for (let i = 0; i < N; i += 2) labelIdx.push(i);
        if (labelIdx[labelIdx.length - 1] !== N - 1) labelIdx.push(N - 1);
        labelIdx.forEach(idx => {
            if (idx < 0 || idx >= N) return;
            const x = xScale(idx);
            const anchor = idx === 0 ? 'start' : (idx === N - 1 ? 'end' : 'middle');
            const t = svgMk(svg, 'text', {x, y:H-5, 'text-anchor':anchor, 'font-size':10, fill:'#cbd5e1', 'font-family':'DM Mono,monospace'});
            t.textContent = String(hours[idx]).padStart(2,'0') + 'h';
        });
    }
}

function makeGeometry(svg, cfg, data) {
    const W = svg.clientWidth || 600;
    const H = 120;
    const PAD_L = 32, PAD_R = 8, PAD_T = 8, PAD_B = 22;
    const innerW = W - PAD_L - PAD_R;
    const innerH = H - PAD_T - PAD_B;
    const hours = data.hours;
    const N = hours.length;
    const yDomain = computeYDomain(cfg, data);
    const xScale = i => N <= 1 ? PAD_L + innerW/2 : PAD_L + (i / (N-1)) * innerW;
    const yScale = v => PAD_T + innerH - ((v - yDomain[0]) / (yDomain[1] - yDomain[0])) * innerH;
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    return {W, H, PAD_L, PAD_R, PAD_T, PAD_B, innerW, innerH, hours, N, xScale, yScale, yDomain};
}
