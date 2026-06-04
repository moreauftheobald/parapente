// ── Graphes du panneau station météo ─────────────────────────
//
// 1) buildStationWindSVG     : timeline vent (rafales + moyen) + flèches direction
// 2) buildStationTempSVG     : double axe température (°C) + humidité (%)
// 3) buildStationPressureSVG : courbe pression (hPa)

function _stationXScale(readings, W, PAD_L, PAD_R) {
    let x0 = Math.min(...readings.map(r => r.min_of_day));
    let x1 = Math.max(...readings.map(r => r.min_of_day));
    if (x1 - x0 < 60) { x0 -= 60; x1 += 60; }
    else { x0 -= 15; x1 += 15; }
    x0 = Math.max(0, x0); x1 = Math.min(1440, x1);
    if (x1 <= x0) x1 = x0 + 60;
    const innerW = W - PAD_L - PAD_R;
    return { x0, x1, scale: m => PAD_L + (m - x0) / (x1 - x0) * innerW };
}

function _stationXGrid(svg, mk, txt, xs, W, PAD_L, PAD_R, PAD_T, baseY) {
    const spanH = (xs.x1 - xs.x0) / 60;
    const step = spanH > 9 ? 3 : (spanH > 5 ? 2 : 1);
    const firstH = Math.ceil(xs.x0 / 60);
    for (let h = firstH; h * 60 <= xs.x1; h++) {
        if (h % step !== 0) continue;
        const x = xs.scale(h * 60);
        mk('line', { x1: x, y1: PAD_T, x2: x, y2: baseY, stroke: '#1a2433', 'stroke-width': 1 });
        txt(x, baseY + 13, String(h).padStart(2, '0') + 'h', 6.5, '#9ca3af', 'middle');
    }
}

function buildStationWindSVG(data) {
    const svg = document.getElementById('station-wind-svg');
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const readings = (data?.readings ?? []).filter(r => r.wind_speed_avg != null || r.wind_speed_max != null);
    if (readings.length < 2) return;

    const W = 440, H = 140;
    const PAD_L = 26, PAD_R = 8, PAD_T = 8, PAD_B = 20;
    const innerH = H - PAD_T - PAD_B;
    const baseY = PAD_T + innerH;

    const xs = _stationXScale(readings, W, PAD_L, PAD_R);

    let ymax = 0;
    readings.forEach(r => {
        const v = r.wind_speed_max ?? r.wind_speed_avg ?? 0;
        if (v > ymax) ymax = v;
    });
    ymax = Math.max(15, Math.ceil(ymax * 1.1));
    const yScale = v => baseY - Math.min(v, ymax) / ymax * innerH;

    const { mk, txt } = svgHelpers(svg);

    [0, Math.round(ymax / 2), ymax].forEach(v => {
        const y = yScale(v);
        mk('line', { x1: PAD_L, y1: y, x2: W - PAD_R, y2: y, stroke: '#1f2937', 'stroke-width': 1 });
        txt(PAD_L - 4, y + 3, v, 6.5, '#9ca3af', 'end');
    });

    _stationXGrid(svg, mk, txt, xs, W, PAD_L, PAD_R, PAD_T, baseY);
    mk('line', { x1: PAD_L, y1: baseY, x2: W - PAD_R, y2: baseY, stroke: '#374151', 'stroke-width': 1 });

    const pathFor = key => {
        let d = '';
        readings.forEach(r => {
            const v = r[key];
            if (v == null) return;
            d += (d === '' ? 'M' : ' L') + xs.scale(r.min_of_day).toFixed(1) + ',' + yScale(v).toFixed(1);
        });
        return d;
    };

    // Rafales area
    const maxPts = readings.filter(r => r.wind_speed_max != null);
    if (maxPts.length > 1) {
        let d = `M${xs.scale(maxPts[0].min_of_day).toFixed(1)},${baseY}`;
        maxPts.forEach(r => { d += ` L${xs.scale(r.min_of_day).toFixed(1)},${yScale(r.wind_speed_max).toFixed(1)}`; });
        d += ` L${xs.scale(maxPts[maxPts.length - 1].min_of_day).toFixed(1)},${baseY} Z`;
        mk('path', { d, fill: '#f97316', 'fill-opacity': .16 });
        mk('path', { d: pathFor('wind_speed_max'), fill: 'none', stroke: '#f97316', 'stroke-width': 1, 'stroke-opacity': .55, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    }

    // Avg line
    const avgD = pathFor('wind_speed_avg');
    if (avgD) mk('path', { d: avgD, fill: 'none', stroke: '#22c55e', 'stroke-width': 1.8, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    readings.forEach(r => {
        if (r.wind_speed_avg == null) return;
        mk('circle', { cx: xs.scale(r.min_of_day).toFixed(1), cy: yScale(r.wind_speed_avg).toFixed(1), r: 1.6, fill: '#22c55e' });
    });

    // Direction arrows (sampled to avoid clutter)
    const dirReadings = readings.filter(r => r.wind_direction != null);
    const arrowStep = Math.max(1, Math.floor(dirReadings.length / 20));
    const arrowY = baseY + 3;
    for (let i = 0; i < dirReadings.length; i += arrowStep) {
        const r = dirReadings[i];
        const ax = xs.scale(r.min_of_day);
        const rot = ((r.wind_direction ?? 0) + 180) % 360;
        const g = mk('g', { transform: `translate(${ax.toFixed(1)},${(arrowY + 8).toFixed(1)}) rotate(${rot}) scale(0.35)` });
        const { mk: mkA } = svgHelpers(g);
        mkA('polygon', { points: '0,-14 5,6 0,2 -5,6', fill: '#38bdf8', 'fill-opacity': .7 });
    }
}

function buildStationTempSVG(data) {
    const svg = document.getElementById('station-temp-svg');
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const readings = (data?.readings ?? []).filter(r => r.temperature != null);
    if (readings.length < 2) return;

    const humReadings = readings.filter(r => r.humidity != null);

    const W = 440, H = 110;
    const PAD_L = 28, PAD_R = 28, PAD_T = 8, PAD_B = 20;
    const innerH = H - PAD_T - PAD_B;
    const baseY = PAD_T + innerH;

    const xs = _stationXScale(readings, W, PAD_L, PAD_R);

    let tMin = Infinity, tMax = -Infinity;
    readings.forEach(r => {
        if (r.temperature < tMin) tMin = r.temperature;
        if (r.temperature > tMax) tMax = r.temperature;
    });
    const tPad = Math.max(2, (tMax - tMin) * 0.15);
    tMin = Math.floor(tMin - tPad);
    tMax = Math.ceil(tMax + tPad);
    if (tMax <= tMin) tMax = tMin + 5;
    const tScale = v => baseY - (v - tMin) / (tMax - tMin) * innerH;

    const hScale = v => baseY - v / 100 * innerH;

    const { mk, txt } = svgHelpers(svg);

    // Y grid (temperature)
    const tSteps = [tMin, Math.round((tMin + tMax) / 2), tMax];
    tSteps.forEach(v => {
        const y = tScale(v);
        mk('line', { x1: PAD_L, y1: y, x2: W - PAD_R, y2: y, stroke: '#1f2937', 'stroke-width': 1 });
        txt(PAD_L - 4, y + 3, v + '°', 6.5, '#f97316', 'end');
    });

    // Y grid right (humidity)
    if (humReadings.length) {
        [0, 50, 100].forEach(v => {
            txt(W - PAD_R + 4, hScale(v) + 3, v + '%', 6.5, '#60a5fa', 'start');
        });
    }

    _stationXGrid(svg, mk, txt, xs, W, PAD_L, PAD_R, PAD_T, baseY);
    mk('line', { x1: PAD_L, y1: baseY, x2: W - PAD_R, y2: baseY, stroke: '#374151', 'stroke-width': 1 });

    // Humidity line (dashed, behind temperature)
    if (humReadings.length > 1) {
        let d = '';
        humReadings.forEach(r => {
            d += (d === '' ? 'M' : ' L') + xs.scale(r.min_of_day).toFixed(1) + ',' + hScale(r.humidity).toFixed(1);
        });
        mk('path', { d, fill: 'none', stroke: '#60a5fa', 'stroke-width': 1.2, 'stroke-dasharray': '4,3', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    }

    // Temperature line
    let d = '';
    readings.forEach(r => {
        d += (d === '' ? 'M' : ' L') + xs.scale(r.min_of_day).toFixed(1) + ',' + tScale(r.temperature).toFixed(1);
    });
    mk('path', { d, fill: 'none', stroke: '#f97316', 'stroke-width': 1.8, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    readings.forEach(r => {
        mk('circle', { cx: xs.scale(r.min_of_day).toFixed(1), cy: tScale(r.temperature).toFixed(1), r: 1.4, fill: '#f97316' });
    });
}

function buildStationPressureSVG(data) {
    const svg = document.getElementById('station-pressure-svg');
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const readings = (data?.readings ?? []).filter(r => r.pressure_hpa != null);
    if (readings.length < 2) return;

    const W = 440, H = 85;
    const PAD_L = 34, PAD_R = 8, PAD_T = 8, PAD_B = 20;
    const innerH = H - PAD_T - PAD_B;
    const baseY = PAD_T + innerH;

    const xs = _stationXScale(readings, W, PAD_L, PAD_R);

    let pMin = Infinity, pMax = -Infinity;
    readings.forEach(r => {
        if (r.pressure_hpa < pMin) pMin = r.pressure_hpa;
        if (r.pressure_hpa > pMax) pMax = r.pressure_hpa;
    });
    const pPad = Math.max(1, (pMax - pMin) * 0.2);
    pMin = Math.floor(pMin - pPad);
    pMax = Math.ceil(pMax + pPad);
    if (pMax <= pMin) pMax = pMin + 3;
    const pScale = v => baseY - (v - pMin) / (pMax - pMin) * innerH;

    const { mk, txt } = svgHelpers(svg);

    [pMin, Math.round((pMin + pMax) / 2), pMax].forEach(v => {
        const y = pScale(v);
        mk('line', { x1: PAD_L, y1: y, x2: W - PAD_R, y2: y, stroke: '#1f2937', 'stroke-width': 1 });
        txt(PAD_L - 4, y + 3, v, 6.5, '#9ca3af', 'end');
    });

    _stationXGrid(svg, mk, txt, xs, W, PAD_L, PAD_R, PAD_T, baseY);
    mk('line', { x1: PAD_L, y1: baseY, x2: W - PAD_R, y2: baseY, stroke: '#374151', 'stroke-width': 1 });

    // Area fill
    let dArea = `M${xs.scale(readings[0].min_of_day).toFixed(1)},${baseY}`;
    readings.forEach(r => { dArea += ` L${xs.scale(r.min_of_day).toFixed(1)},${pScale(r.pressure_hpa).toFixed(1)}`; });
    dArea += ` L${xs.scale(readings[readings.length - 1].min_of_day).toFixed(1)},${baseY} Z`;
    mk('path', { d: dArea, fill: '#a78bfa', 'fill-opacity': .1 });

    // Line
    let d = '';
    readings.forEach(r => {
        d += (d === '' ? 'M' : ' L') + xs.scale(r.min_of_day).toFixed(1) + ',' + pScale(r.pressure_hpa).toFixed(1);
    });
    mk('path', { d, fill: 'none', stroke: '#a78bfa', 'stroke-width': 1.6, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    readings.forEach(r => {
        mk('circle', { cx: xs.scale(r.min_of_day).toFixed(1), cy: pScale(r.pressure_hpa).toFixed(1), r: 1.3, fill: '#a78bfa' });
    });
}
