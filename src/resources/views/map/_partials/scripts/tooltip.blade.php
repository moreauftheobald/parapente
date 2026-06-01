// ── Tooltip multi-modèles ────────────────────────────────────
// COMPASS_8 est défini dans config.blade.php.

function degToCompass(deg) {
    return COMPASS_8[Math.round(((deg % 360) + 360) % 360 / 45) % 8];
}

function formatTooltipValue(cfg, v) {
    if (v == null) return '—';
    switch (cfg.id) {
        case 'wind-dir':  return Math.round(v) + '° ' + degToCompass(v);
        case 'precip':    return v.toFixed(1) + ' mm/h';
        case 'temp':      return v.toFixed(1) + '°C';
        case 'humidity':  return Math.round(v) + '%';
        case 'ceiling':   return Math.round(v) + ' m';
        default:          return v.toFixed(1) + ' km/h';
    }
}

// Attache un overlay + un curseur vertical sur le SVG du graphe.
// Met à jour app.tooltip au survol pour afficher les valeurs des
// 10 modèles + le consensus à l'heure pointée.
function attachTooltipHandlers(svg, cfg, data, geo, app) {
    const { PAD_L, PAD_T, PAD_B, innerW, innerH, hours, N, xScale, H, W } = geo;

    // Curseur vertical (initialement caché hors zone)
    const cursor = svgMk(svg, 'line', {
        x1: -10, y1: PAD_T, x2: -10, y2: PAD_T + innerH,
        stroke: 'rgba(255,255,255,.45)', 'stroke-width': 1, 'stroke-dasharray': '3,3',
        'pointer-events': 'none'
    });

    // Overlay invisible qui capte les events
    const overlay = svgMk(svg, 'rect', {
        x: PAD_L, y: PAD_T, width: innerW, height: innerH,
        fill: 'transparent', 'pointer-events': 'all', style: 'cursor:crosshair;'
    });

    const onMove = (e) => {
        const rect   = svg.getBoundingClientRect();
        // Le SVG utilise viewBox 0 0 W H ; on convertit clientX → unités viewBox
        const scaleX = rect.width  > 0 ? W / rect.width  : 1;
        const localX = (e.clientX - rect.left) * scaleX;

        // Trouver l'index d'heure le plus proche
        let bestIdx = 0, bestDist = Infinity;
        for (let i = 0; i < N; i++) {
            const d = Math.abs(localX - xScale(i));
            if (d < bestDist) { bestDist = d; bestIdx = i; }
        }
        const hour    = hours[bestIdx];
        const xCursor = xScale(bestIdx);
        cursor.setAttribute('x1', xCursor);
        cursor.setAttribute('x2', xCursor);

        // Construire les rows pour le tooltip (modèles avec valeur)
        const rows = data.models.map(m => {
            const v = data.data[hour]?.[m.id]?.[cfg.key];
            if (v == null) return null;
            return { id: m.id, color: m.color, name: m.name, value: formatTooltipValue(cfg, +v) };
        }).filter(Boolean);

        let consensus = null;
        if (cfg.consensusKey) {
            const cv = data.consensus[hour]?.[cfg.consensusKey];
            if (cv != null) consensus = formatTooltipValue(cfg, +cv);
        }

        // Mise à jour Alpine
        // Format : "14h00" en mode jour, "Jeu 07 · 14h00" en mode 5 jours
        let hourLabel = String(hour).padStart(2,'0') + 'h00';
        if (data.viewMode === 'fivedays') {
            const dayIdx    = Math.floor(hour / 24);
            const hourOfDay = hour % 24;
            const dayLabel  = data.day_labels?.[dayIdx]?.label || '';
            hourLabel = (dayLabel ? dayLabel + ' · ' : '') + String(hourOfDay).padStart(2,'0') + 'h00';
        }
        app.tooltip.hour      = hourLabel;
        app.tooltip.consensus = consensus;
        app.tooltip.rows      = rows;
        app.tooltip.visible   = true;

        // Position : suit le curseur, évite les bords
        const tw = 240, th = Math.min(320, rows.length * 20 + 60);
        const pos = positionTooltip(e.clientX, e.clientY, tw, th);
        app.tooltip.x = pos.x;
        app.tooltip.y = pos.y;
    };

    const onLeave = () => {
        cursor.setAttribute('x1', -10);
        cursor.setAttribute('x2', -10);
        app.tooltip.visible = false;
    };

    overlay.addEventListener('mousemove', onMove);
    overlay.addEventListener('mouseleave', onLeave);
}
