// ── Icônes balises (SpotAir/FFVL) ────────────────────────────
// URL doc : https://www.spotair.mobi/help/api.php#icon_balise
//
// Paramètres :
//   d=  direction du vent en convention FROM (d'où vient le vent)
//   v=  vitesse moyenne affichée (km/h, entier)
//   t=  tendance {-2..+2} pré-calculée par le backend
//   bg= statut : w=actif | l=relevé en retard | d=inactif
//   c=  couleur : g=vert (5-20) | b=bleu (<5) | o=orange (>20)
//
// reading.wind_direction est toujours en convention FROM (les providers
// normalisent à l'ingestion — cf. PiouPiouProvider::windDirectionFrom),
// on le transmet donc tel quel au paramètre d=.

const SPOTAIR_BALISE_URL = 'https://www.spotair.mobi/icones/balises/balise.svg.php';

function baliseIconColor(speedKmh) {
    if (speedKmh === null) return 'g';
    if (speedKmh < 5)  return 'b';
    if (speedKmh > 20) return 'o';
    return 'g';
}

// Seuils de fraîcheur visuelle. Calibrés sur la cadence native la
// plus lente (METAR ~30 min) pour ne pas signaler comme "en retard"
// des balises qui fonctionnent normalement. La désactivation totale
// en base (active=false) reste indépendante et fixée à 7 jours
// sans lecture, gérée par les jobs de polling.
function baliseIconBg(readAtIso) {
    if (!readAtIso) return 'd';
    const ageMin = (Date.now() - new Date(readAtIso).getTime()) / 60000;
    if (ageMin > 120) return 'd';   // > 2h : inactif (dead)
    if (ageMin > 30)  return 'l';   // 30 min – 2h : en retard (late)
    return 'w';                      // < 30 min : actif (white)
}

function baliseIconUrl(reading) {
    if (!reading) return `${SPOTAIR_BALISE_URL}?bg=d&c=g&v=0&d=0&t=0`;

    const v  = reading.wind_speed_avg !== null ? Math.round(reading.wind_speed_avg) : 0;
    const d  = reading.wind_direction !== null ? (((reading.wind_direction % 360) + 360) % 360) : 0;
    const t  = reading.trend ?? 0;
    const c  = baliseIconColor(reading.wind_speed_avg);
    const bg = baliseIconBg(reading.read_at);

    return `${SPOTAIR_BALISE_URL}?d=${d}&v=${v}&t=${t}&bg=${bg}&c=${c}`;
}

// Tooltip au survol : nom + valeurs + horodatage
function baliseTooltipHtml(b) {
    const r = b.reading;
    if (!r) {
        return `<strong>${b.name}</strong><br><span style="color:#9ca3af;">Aucune lecture récente</span>`;
    }
    const v   = r.wind_speed_avg !== null ? r.wind_speed_avg.toFixed(1) + ' km/h' : '—';
    const min = r.wind_speed_min !== null ? Math.round(r.wind_speed_min) : '—';
    const max = r.wind_speed_max !== null ? Math.round(r.wind_speed_max) : '—';
    const dir = r.wind_direction !== null ? r.wind_direction + '°' : '—';
    const ageMin = Math.round((Date.now() - new Date(r.read_at).getTime()) / 60000);
    const ageStr = ageMin < 1 ? "à l'instant" : (ageMin < 60 ? `il y a ${ageMin} min` : `il y a ${Math.round(ageMin/60)} h`);

    return `<strong>${b.name}</strong><br>` +
        `<span style="font-family:'DM Mono',monospace;">` +
        `${dir} · ${v}<br>` +
        `<span style="color:#9ca3af;">min ${min} / max ${max} km/h</span></span><br>` +
        `<span style="color:#6b7280;font-size:11px;">${ageStr}</span>`;
}
