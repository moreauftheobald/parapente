// ── Icônes des sites de vol (SpotAir/FFVL) ──────────────────
// URL doc : https://www.spotair.mobi/help/api.php (#icon_spot)
//
// Paramètres construits :
//   p=1  : pratique = parapente
//   t=   : type (1 = décollage, 2 = atterrissage, 4 = pente école,
//          8 = treuillage). On reste à 1 pour l'instant — distinction
//          décollage/atterrissage à venir si ajout d'une colonne `type`
//          aux sites.
//   n=   : niveau recommandé (3 vert, 4 bleu, 5 marron) selon site.level
//   o=   : bitmask 8 secteurs des orientations favorables, calculé
//          depuis (wind_dir_min, wind_dir_max). N=1, NE=2, E=4, SE=8,
//          S=16, SO=32, O=64, NO=128.
//
// Le statut météo du jour (vert/orange/rouge) n'est PAS encodable dans
// l'icône SpotAir → on l'affiche via un halo CSS coloré dans le wrapper
// (cf. .pg-site-marker.pg-status-* dans styles).

const SPOTAIR_SPOT_URL = 'https://www.spotair.mobi/icones/spots/spot.svg.php';

const SITE_LEVEL_TO_SPOTAIR = {
    debutant:      3,  // Vert  (IPPI 3)
    intermediaire: 4,  // Bleu  (IPPI 4)
    confirme:      5,  // Marron (IPPI 5)
};

// Convertit une plage de directions [min, max] en bitmask 8 secteurs.
// Gère le wrap autour du Nord (ex: 350° → 20° = N + NE).
function dirsToBitmask(min, max) {
    if (min == null || max == null) return 0;
    const sectors = [0, 45, 90, 135, 180, 225, 270, 315];
    let bits = 0;
    for (let i = 0; i < 8; i++) {
        const c = sectors[i];
        const inRange = min <= max
            ? (c >= min && c <= max)
            : (c >= min || c <= max);
        if (inRange) bits |= (1 << i);
    }
    return bits;
}

function siteIconUrl(site, type = 1) {
    const params = ['p=1', `t=${type}`];
    const n = SITE_LEVEL_TO_SPOTAIR[site.level];
    if (n) params.push(`n=${n}`);
    const o = dirsToBitmask(site.wind_dir_min, site.wind_dir_max);
    if (o > 0) params.push(`o=${o}`);
    return `${SPOTAIR_SPOT_URL}?${params.join('&')}`;
}

// Badge user_scoring : pastille en bas-droite du marker quand
// l'utilisateur a un scoring perso sur ce site.
//   'active'   → pastille bleue pleine ("scoring actif")
//   'inactive' → pastille creuse grise ("scoring enregistré mais dormant")
//   null/autre → rien
function userScoringBadgeHtml(state) {
    if (state === 'active') {
        return `<span class="pg-user-badge pg-user-badge-active" title="Scoring perso actif"></span>`;
    }
    if (state === 'inactive') {
        return `<span class="pg-user-badge pg-user-badge-inactive" title="Scoring perso enregistré (inactif)"></span>`;
    }
    return '';
}

// HTML du marqueur : un wrapper coloré (halo statut météo) qui
// contient l'icône SpotAir, plus un badge optionnel pour le
// scoring perso de l'utilisateur authentifié.
function siteIconHtml(site, status, type = 1) {
    const url = siteIconUrl(site, type);
    const badge = userScoringBadgeHtml(site?.user_scoring);
    return `<div class="pg-site-marker pg-status-${status}"><img src="${url}" alt="">${badge}</div>`;
}
