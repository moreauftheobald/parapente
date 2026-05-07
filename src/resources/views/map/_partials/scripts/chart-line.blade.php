// ── Graphe linéaire multi-modèles ────────────────────────────
// 1 trait par modèle dans sa couleur + 1 trait consensus en
// pointillé blanc épais sur fond noir (sauf wind-min/max sans
// consensus séparé en base).
function buildLineChart(svgId, cfg, data, app) {
    const svg = document.getElementById(svgId);
    if (!svg) return;
    while (svg.firstChild) svg.removeChild(svg.firstChild);

    const geo = makeGeometry(svg, cfg, data);
    drawBackgroundBands(svg, cfg, data, geo);
    drawAxes(svg, cfg, geo, data);

    // 1 ligne par modèle
    data.models.forEach(model => {
        const pts = data.hours.map((h, i) => {
            const v = data.data[h]?.[model.id]?.[cfg.key];
            return v == null ? null : {x: geo.xScale(i), y: geo.yScale(+v), v: +v};
        });
        const d = buildLinePath(pts, cfg.wrap);
        if (d) svgMk(svg, 'path', {d, fill:'none', stroke:model.color, 'stroke-width':1.2, 'stroke-opacity':.7, 'stroke-linecap':'round', 'stroke-linejoin':'round'});
    });

    // Ligne consensus (par-dessus, double trait noir épais + blanc pointillé)
    if (cfg.consensusKey) {
        const pts = data.hours.map((h, i) => {
            const v = data.consensus[h]?.[cfg.consensusKey];
            return v == null ? null : {x: geo.xScale(i), y: geo.yScale(+v), v: +v};
        });
        const d = buildLinePath(pts, cfg.wrap);
        if (d) {
            svgMk(svg, 'path', {d, fill:'none', stroke:'#000', 'stroke-width':3.5, 'stroke-opacity':.5, 'stroke-linecap':'round', 'stroke-linejoin':'round'});
            svgMk(svg, 'path', {d, fill:'none', stroke:'#fff', 'stroke-width':1.6, 'stroke-dasharray':'5,3', 'stroke-linecap':'round', 'stroke-linejoin':'round'});
        }
    }

    if (app) attachTooltipHandlers(svg, cfg, data, geo, app);
}
