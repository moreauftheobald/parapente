// ── Icônes stations météo ─────────────────────────────────────
// L.icon pointant vers /icons-cache/station/{network}/{freshness}.svg.
// Même pattern que les icônes SpotAir : SVG généré côté serveur au
// premier hit, servi directement par nginx ensuite (try_files).
// 3 réseaux × 3 états de fraîcheur = 9 SVG max sur disque.

function stationFreshnessKey(observedAtIso) {
    if (!observedAtIso) return 'dead';
    const ageMin = (Date.now() - new Date(observedAtIso).getTime()) / 60000;
    if (ageMin > 120) return 'dead';
    if (ageMin > 60)  return 'stale';
    return 'fresh';
}

function stationIconUrl(network, freshnessKey) {
    return `/icons-cache/station/${network}/${freshnessKey}.svg`;
}

function stationTooltipHtml(s) {
    const r = s.reading;
    if (!r) {
        return `<strong>${s.name}</strong><br><span style="color:#9ca3af;">Aucune observation récente</span>`;
    }
    const parts = [];
    if (r.wind_speed_avg !== null) parts.push(`Vent ${r.wind_speed_avg.toFixed(1)} km/h`);
    if (r.wind_direction !== null) parts.push(`${r.wind_direction}°`);
    if (r.temperature !== null)    parts.push(`${r.temperature.toFixed(1)} °C`);
    const ageStr = formatAge(r.observed_at);
    return `<strong>${s.name}</strong>` +
        (parts.length ? `<br><span style="font-family:'DM Mono',monospace;">${parts.join(' · ')}</span>` : '') +
        `<br><span style="color:#6b7280;font-size:11px;">${ageStr}</span>`;
}
