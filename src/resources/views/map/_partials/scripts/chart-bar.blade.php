// ── Graphe en barres groupées (pour les précipitations) ──────
// Pour chaque heure : N barres fines côte à côte (1 par modèle).
// La convergence se lit visuellement à l'alignement des hauteurs.
// Un petit triangle blanc au-dessus du groupe matérialise la valeur
// consensus.
function buildBarChart(svgId, cfg, data, app) {
    const svg = document.getElementById(svgId);
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const geo = makeGeometry(svg, cfg, data);
    drawBackgroundBands(svg, cfg, data, geo);
    drawAxes(svg, cfg, geo, data);

    const { PAD_L, PAD_T, innerH, innerW, hours, N, yScale } = geo;
    const baseY = PAD_T + innerH;
    const hourW  = innerW / N;
    const groupW = hourW * 0.78;
    const barW   = Math.max(1.2, groupW / data.models.length);

    hours.forEach((h, i) => {
        const xCenter = PAD_L + i * hourW + hourW/2;
        const groupX  = xCenter - groupW/2;
        data.models.forEach((m, mi) => {
            const v = data.data[h]?.[m.id]?.[cfg.key];
            if (v == null || v <= 0) return;
            const yTop = yScale(+v);
            const x    = groupX + mi * (groupW / data.models.length);
            svgMk(svg, 'rect', {x:x.toFixed(1), y:yTop.toFixed(1), width:barW.toFixed(1), height:Math.max(1, baseY - yTop).toFixed(1), fill:m.color, 'fill-opacity':.85, rx:0.5});
        });

        // Marqueur consensus au-dessus du groupe (petit triangle)
        const cv = data.consensus[h]?.[cfg.consensusKey];
        if (cv != null && cv > 0) {
            const cy = yScale(+cv);
            svgMk(svg, 'polygon', {points:`${xCenter-3.5},${cy-5} ${xCenter+3.5},${cy-5} ${xCenter},${cy-1}`, fill:'#fff', 'fill-opacity':.85});
        }
    });

    if (app) attachTooltipHandlers(svg, cfg, data, geo, app);
}
