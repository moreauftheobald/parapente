// ── Configuration globale ────────────────────────────────────
// Constantes partagées par tous les modules (icônes, couleurs,
// modèles météo, palettes, etc.). Doit être inclus en premier.

const BASEMAP_LIST=[
    {key:'topo',label:'Topographique',icon:'⛰',desc:'Relief & courbes de niveau',url:'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',attribution:'© OpenStreetMap contributors, © OpenTopoMap',maxZoom:17},
    {key:'osm',label:'Standard',icon:'🗺',desc:'OpenStreetMap classique',url:'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',attribution:'© OpenStreetMap contributors',maxZoom:19},
    {key:'satellite',label:'Satellite',icon:'🛰',desc:'Vue aérienne ESRI',url:'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',attribution:'© Esri, Maxar',maxZoom:18},
    {key:'dark',label:'Sombre',icon:'🌙',desc:'CartoDB Dark Matter',url:'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',attribution:'© OpenStreetMap © CARTO',maxZoom:19},
    {key:'light',label:'Clair',icon:'☀',desc:'CartoDB Voyager',url:'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',attribution:'© OpenStreetMap © CARTO',maxZoom:19},
];

// Zoom minimum de la carte : empêche de dézoomer au-delà de ~un quart de
// la France. Évite de surcharger l'écran au boot et borne le volume de
// tuiles/marqueurs. La vue par défaut cadre la France entière à ce niveau.
const MAP_MIN_ZOOM = 6;
const MAP_DEFAULT_VIEW = { lat: 46.6, lng: 2.5, zoom: 6, basemap: 'topo' };

// Jours de la semaine abrégés (index = jour ISO ; ex: dt.getDay() retourne 0..6)
const DAY_NAMES_SHORT = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];
// Namespace SVG (pour createElementNS)
const SVG_NS = 'http://www.w3.org/2000/svg';

// ── Helpers partagés ────────────────────────────────────────
function formatAge(isoTimestamp) {
    if (!isoTimestamp) return 'aucune lecture';
    const ageMin = Math.round((Date.now() - new Date(isoTimestamp).getTime()) / 60000);
    if (ageMin < 1)  return "à l'instant";
    if (ageMin < 60) return `il y a ${ageMin} min`;
    return `il y a ${Math.round(ageMin / 60)} h`;
}

function positionTooltip(clientX, clientY, tooltipW, tooltipH) {
    let tx = clientX + 14, ty = clientY + 14;
    if (tx + tooltipW > window.innerWidth)  tx = clientX - tooltipW - 14;
    if (ty + tooltipH > window.innerHeight) ty = clientY - tooltipH - 14;
    return { x: Math.max(8, tx), y: Math.max(8, ty) };
}

// ── Configuration des 6 graphes du panel multi-modèles ──────
const CHART_CONFIGS = [
    {id:'wind-avg', title:'Vent — Vitesse moyenne',   unit:'km/h',  type:'line', key:'wind_avg',    yMin:0,    yMax:'auto', favBand:'speed', consensusKey:'wind_speed', wrap:false},
    {id:'wind-max', title:'Vent — Rafales (max)',     unit:'km/h',  type:'line', key:'wind_max',    yMin:0,    yMax:'auto', favBand:null,    consensusKey:'wind_gust',  wrap:false},
    {id:'wind-dir', title:'Direction du vent',        unit:'',      type:'line', key:'wind_dir',    yMin:0,    yMax:360,    favBand:'dir',   consensusKey:'wind_dir',   wrap:true,  yTicks:'compass'},
    {id:'ceiling',  title:'Plafond de vol estimé',    unit:'m',     type:'line', key:'cloud_base',  yMin:0,    yMax:'auto', favBand:null,    consensusKey:'cloud_base', wrap:false},
    {id:'precip',   title:'Précipitations',           unit:'mm/h',  type:'bar',  key:'precip',      yMin:0,    yMax:'auto', favBand:null,    consensusKey:'precip',     wrap:false},
    {id:'humidity', title:'Humidité relative',        unit:'%',     type:'line', key:'humidity',    yMin:0,    yMax:100,    favBand:null,    consensusKey:null,         wrap:false},
    {id:'temp',     title:'Température',              unit:'°C',    type:'line', key:'temperature', yMin:'auto', yMax:'auto', favBand:null,  consensusKey:null,         wrap:false},
];

// Labels compas pour la direction du vent (provenance) — utilisé sur l'axe Y
const COMPASS_TICKS = [
    {deg:0,   label:'N'},
    {deg:90,  label:'E'},
    {deg:180, label:'S'},
    {deg:270, label:'O'},
    {deg:360, label:'N'},
];

// 8 directions cardinales (utilisé par degToCompass dans les tooltips)
const COMPASS_8 = ['N','NE','E','SE','S','SO','O','NO'];

// Utilisateur authentifié (null si invité). Sert au bandeau « Scoring
// perso ` pseudo ` du volet droit + au filtre « Mes sites ». Le scoring
// perso lui-même est appliqué côté serveur sur /api/sites + /api/sites/_id_/scores
// quand le cookie de session est présent.
//
// Encodage via une sortie brute Blade (bang-bang-bang) plutôt que par la
// directive json dédiée — son regex de parsing ne tolère pas les
// arguments multi-lignes avec tableau imbriquant des appels de fonction.
// Important : on évite TOUTE syntaxe Blade dans ce commentaire (pas de
// accolades doubles, pas de dièse-paren-paren-paren) car Blade compile
// aussi à l'intérieur des commentaires JS.
const AUTH_USER = {!! json_encode(auth()->check() ? [
    'id'           => auth()->id(),
    'name'         => auth()->user()->name,
    'pseudo'       => auth()->user()->pseudo,
    'display_name' => auth()->user()->displayName(),
] : null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};

// Dernière vue carte enregistrée côté profil (null si invité ou jamais
// définie). Prioritaire sur localStorage au boot (suivi multi-appareils).
const USER_MAP_VIEW = {!! json_encode(auth()->check() ? auth()->user()->map_view : null, JSON_UNESCAPED_SLASHES) !!};
