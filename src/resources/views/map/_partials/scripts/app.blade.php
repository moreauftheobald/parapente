// ── Composant Alpine principal ───────────────────────────────
// Tout l'état et le comportement de la vue carte. Doit être inclus
// en dernier (utilise toutes les fonctions/constantes définies avant).
function mapApp(){return{
    map:null,tl:null,markers:{},
    sites:[],allScores:{},sunWindows:{},dayQuality:{},
    // Scoring perso : 'user' | 'global' par site_id (renvoyé par /api/sites/{id}/scores).
    // Vide pour les invités, ou pour les sites sans scoring perso actif.
    scoringSource:{},
    // Fraîcheur du scoring (watchdog sidecar) : {stale, age_minutes, ...} ou null.
    // Alimente le bandeau « prévisions non rafraîchies » (cf. ScoringFreshness).
    scoringStale:null,
    days:[],selectedDayIdx:0,
    site:{},
    currentBasemap:'topo',basemapList:BASEMAP_LIST,
    dayDropOpen:false,dayDropPos:{top:0,left:0},
    bmDropOpen:false,bmDropPos:{top:0,left:0,width:0},
    // Volet gauche (onglets Paramètres / Légende) ; volet droit (détail).
    // leftOpen / rightOpen sont consommés ET pilotés par le shell global
    // via les boutons ☰ / ? de la navbar — on les expose donc au scope
    // racine Alpine pour que le shell les voie. Le volet droit n'a pas
    // d'ouverture manuelle : il s'ouvre automatiquement quand l'utilisateur
    // sélectionne un site/balise (cf. openRightPanel/closeRightPanel).
    // Le volet gauche est ouvert d'emblée en desktop (≥1024px) — c'est le
    // comportement historique de la vue carte ; sur mobile il reste fermé.
    leftOpen: window.AppShell.isDesktop(),
    lpTab:'params', rightOpen:false, selectedFeature:null,
    // Filtres d'affichage des sites par statut météo
    showGreen:true, showOrange:true, showRed:true,
    // Filtre « Mes sites » : ne montrer que les sites avec scoring perso (actif ou inactif).
    // Disponible uniquement quand l'utilisateur est connecté.
    onlyMyScorings:false,
    authUser: AUTH_USER,
    // Sites masqués par l'utilisateur connecté (cf. /profil/sites-masques).
    // Toujours filtrés de la carte (pas de toggle ici — la page de gestion
    // est le panneau de contrôle). Vide pour les invités. FF_site_blacklist.md.
    hiddenSiteIds:[],

    // ── Volet droit (site) : onglets + données ──
    rpTab:'synth',                                 // synth | score | mod (refonte v2)
    scZoom:'1j', scMode:'global',                  // onglet Scoring : zoom 1j|5j, mode global|perso
    modVar:'speed', modZoom:'1j', modHidden:{},    // onglet Modèles : variable, zoom 1j|5j, modèles masqués
    MOD_VARS,                                       // catalogue variables ribbon (panel-ribbon)
    windRoseOpen:false, wrPlaying:false, wrIdx:0,   // rose des vents animée (modale, panel-windrose)
    // Popup flottante balise/station (refonte v2 — cohabite avec le drawer site).
    featurePopup:{ open:false, type:null, id:null, tab:'rose' },
    fpData:null, fpLoading:false, fpWindow:24, _fpAnchor:null, fpReady:false,
    fpComparData:null, fpComparLoading:false, fpComparVar:'wind_mean', // onglet Évolution
    chartData:null, chartLoading:false, chartSite:null,   // onglet « Synthèse » (= ancienne popup)
    multimodelData:null,  multimodelLoading:false,        // onglet « Modèles du jour »
    multimodel5Data:null, multimodel5Loading:false,       // onglet « Modèles 5 jours »
    // Cache mémoire des payloads /api/sites/{id}/multimodel par YYYY-MM-DD.
    // Permet d'éviter de re-fetcher les jours déjà chargés quand l'utilisateur
    // navigue entre les onglets « Modèles du jour » / « 5 jours » et entre
    // les jours sélectionnés. Reset à chaque changement de site (pickSite,
    // closeRightPanel).
    _multimodelByDay:{},
    chartCollapsed:{}, chartCollapsed5:{},                // état replié de chaque section graphe
    tooltip:{visible:false, x:0, y:0, hour:'', consensus:null, rows:[]},  // tooltip flottant des graphes
    // Tooltip dédié aux cellules de l'onglet « Détail scoring » — utile
    // notamment pour les cellules split (2 valeurs à afficher).
    votingTip:{visible:false, x:0, y:0, lines:[]},

    // Balises météo — un MarkerClusterGroup par réseau
    // (les clés DOIVENT correspondre à `balises.source` côté API
    //  : pioupiou / windy)
    BALISE_NETWORKS: [
        { key:'pioupiou', label:'PiouPiou', icon:'🪁' },
        { key:'windy',    label:'Windy',    icon:'🌬️' },
    ],
    balises:[],
    networksVisible:{ pioupiou:true, windy:true },
    _balisesLayers:{},        // { source: L.markerClusterGroup() }
    _balisesMarkers:{},       // { baliseId: L.marker }
    _balisesTimer:null,
    _balisesAbort:null,
    // Volet droit (balise) : relevés + historique du jour
    baliseData:null, baliseLoading:false, _baliseObj:null,

    // Stations météo (MF / METAR / Infoclimat) — MarkerClusterGroup par réseau
    // Désactivées par défaut (perf) ; visibles uniquement à partir de zoom 9.
    STATION_NETWORKS: [
        { key:'mf',         label:'Météo-France', icon:'🏛️', color:'#3b82f6' },
        { key:'metar',      label:'METAR',        icon:'✈️',  color:'#7c3aed' },
        { key:'infoclimat', label:'Infoclimat',   icon:'🌡️', color:'#16a34a' },
    ],
    STATION_MIN_ZOOM: 9,
    weatherStations:[],
    stationNetworksVisible:{ mf:false, metar:false, infoclimat:false },
    _stationsLayers:{},
    _stationsMarkers:{},
    _stationsTimer:null,
    _stationsAbort:null,
    _stationsMoveTimer:null,  // debounce pour rechargement bbox au pan/zoom
    // Volet droit (station) : relevés + historique du jour
    stationData:null, stationLoading:false, _stationObj:null, stationTableOpen:false,

    // Configuration des graphes exposée pour le template
    CHART_CONFIGS,

    // Cluster group dédié aux sites de vol (perf : chunkedLoading évite le freeze
    // quand le nombre de sites augmente ; disableClusteringAtZoom=11 pour garder
    // les markers individuels en zoom rapproché).
    _sitesCluster: null,

    async init(){
        await this.$nextTick();
        this.initMap();
        await this.loadSites();
        await this.loadBalises();
        this._balisesTimer = setInterval(() => this.loadBalises(), 5 * 60 * 1000);
        // Stations désactivées par défaut — le premier loadStations() est
        // déclenché par toggleStationNetwork() quand l'utilisateur active un réseau.
        this._stationsTimer = setInterval(() => {
            if(this._anyStationNetworkOn()) this.loadStations();
        }, 5 * 60 * 1000);

        // Rechargement bbox des stations au pan/zoom (debounce 500ms).
        // Gère aussi le seuil de zoom : retire/ajoute les layers
        // quand on franchit STATION_MIN_ZOOM.
        this.map.on('moveend', () => {
            if(!this._anyStationNetworkOn()) return;
            clearTimeout(this._stationsMoveTimer);
            if(this.map.getZoom() < this.STATION_MIN_ZOOM){
                this._removeAllStationLayers();
                return;
            }
            this._stationsMoveTimer = setTimeout(() => this.loadStations(), 500);
        });

        // Popup flottante balise/station : reste collée au marqueur au
        // pan/zoom, se ferme au clic sur la carte (pas au clic marqueur,
        // qui stoppe la propagation → permet d'en rouvrir une autre).
        this.map.on('move zoom', () => { if(this.featurePopup.open) this.positionFeaturePopup(); });
        this.map.on('click', () => { if(this.featurePopup.open) this.closeFeaturePopup(); });

        // Re-dimensionner Leaflet quand un volet s'ouvre ou se ferme + forcer
        // un repaint complet du document. Sur Chrome Android, on a observé
        // que l'ouverture d'un volet ne déclenchait pas de recalcul de
        // layout (le volet apparaissait minuscule, la navbar disparaissait
        // visuellement) tant que l'utilisateur ne mettait pas l'app en
        // background puis foreground. Le toggle d'une transform invisible
        // sur <html> + dispatch resize force Chrome à tout recalculer.
        const onPanelChange = () => {
            const root = document.documentElement;
            root.style.transform = 'translateZ(0)';
            requestAnimationFrame(() => {
                root.style.transform = '';
                window.dispatchEvent(new Event('resize'));
            });
        };
        this.$watch('leftOpen',  onPanelChange);
        this.$watch('rightOpen', onPanelChange);

        // Re-rendu des graphes du volet droit sur redimensionnement (debounce 250ms)
        let _resizeTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(_resizeTimer);
            _resizeTimer = setTimeout(() => {
                if (!this.rightOpen) return;
                if (this.selectedFeature?.type === 'site') {
                    if (this.rpTab === 'synth' && this.chartData) this.renderSynthese();
                    if (this.rpTab === 'score') this.renderScoring();
                    if (this.rpTab === 'mod'   && this.modelsPayload) this.renderModels();
                } else if (this.selectedFeature?.type === 'balise' && this.baliseData) {
                    this.renderBaliseCharts();
                }
            }, 250);
        });
    },

    initMap(){
        this.map=L.map('map',{center:[49.1,5.5],zoom:7,zoomControl:false});
        L.control.zoom({position:'topright'}).addTo(this.map);
        const b=BASEMAP_LIST[0];
        this.tl=L.tileLayer(b.url,{attribution:b.attribution,maxZoom:b.maxZoom}).addTo(this.map);

        // Le conteneur #map peut ne pas avoir sa taille finale au moment
        // du L.map() : le volet gauche du shell (frère flex du <main>,
        // ouvert d'emblée en desktop) n'a pas toujours pris sa largeur,
        // surtout en refresh « soft » où le CSS Vite vient du cache et le
        // JS s'exécute avant la stabilisation du layout. La carte mémorise
        // alors une origine de projection erronée et n'est jamais recalée
        // (onPanelChange ne se déclenche que sur un *changement* de volet).
        // Conséquence : les marqueurs (balises surtout, jamais re-rendus
        // après le boot) restent décalés et « décrochent » au zoom.
        //
        // Un ResizeObserver règle ça de façon déterministe : dès que #map
        // atteint (ou change) sa taille, invalidateSize() recale l'origine
        // et repositionne tous les marqueurs. Le zoom ne modifie pas la
        // taille du conteneur → aucune interférence. rAF pour coalescer
        // les rafales pendant les transitions de volet.
        if (typeof ResizeObserver !== 'undefined') {
            let raf = null;
            this._mapResizeObserver = new ResizeObserver(() => {
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(() => this.map?.invalidateSize({ animate: false }));
            });
            const el = document.getElementById('map');
            if (el) this._mapResizeObserver.observe(el);
        }
    },

    switchBasemap(key){
        if(key===this.currentBasemap)return;
        const b=BASEMAP_LIST.find(x=>x.key===key);if(!b)return;
        this.map.removeLayer(this.tl);
        this.tl=L.tileLayer(b.url,{attribution:b.attribution,maxZoom:b.maxZoom}).addTo(this.map);
        this.currentBasemap=key;
    },
    toggleDayDrop(btn){if(!this.dayDropOpen){const r=btn.getBoundingClientRect();this.dayDropPos={top:r.bottom+6,left:r.left};}this.dayDropOpen=!this.dayDropOpen;this.bmDropOpen=false;},
    toggleBmDrop(btn){if(!this.bmDropOpen){const r=btn.getBoundingClientRect();this.bmDropPos={top:r.bottom+6,left:r.left,width:r.width};}this.bmDropOpen=!this.bmDropOpen;this.dayDropOpen=false;},
    get currentBasemapObj(){return BASEMAP_LIST.find(b=>b.key===this.currentBasemap)??BASEMAP_LIST[0];},

    // ── Volet droit : ouverture / fermeture ──────────────────────
    // Le pan & invalidateSize sont déclenchés via $watch('rightOpen') (init).
    openRightPanel(){
        this.rightOpen=true;
        // Sur mobile, on referme le volet gauche pour ne pas masquer la carte.
        if (window.matchMedia('(max-width: 1023px)').matches) this.leftOpen=false;
        // Recentrer la carte sur le marqueur une fois la transition finie.
        if (this.selectedFeature?.lat!=null) {
            setTimeout(()=>this.map?.panTo([this.selectedFeature.lat,this.selectedFeature.lng]), 280);
        }
    },
    _markerEl(m){ return m.getElement()?.querySelector('.pg-site-marker'); },
    _clearAllMarkerSelection(){
        Object.values(this.markers).forEach(m => this._markerEl(m)?.classList.remove('selected'));
    },
    closeRightPanel(){
        this.rightOpen=false;
        this.selectedFeature=null;
        this.chartData=null; this.chartSite=null;
        this.multimodelData=null; this.multimodel5Data=null;
        this._multimodelByDay={};
        this.baliseData=null; this._baliseObj=null;
        this.stationData=null; this._stationObj=null;
        this.closeWindRose();
        if(typeof destroyModelsChart==='function') destroyModelsChart();
        this._clearAllMarkerSelection();
    },

    /**
     * Boot de la carte : charge le « map bundle » (1 seul appel) puis,
     * si user authentifié, les overrides de scoring perso (1 appel).
     * Cf. FF_map_bundle_cache.md.
     *
     * Avant : 1 appel /api/sites + N appels /api/sites/{id}/scores (15
     * requêtes pour 14 sites). Maintenant : 1 ou 2 appels max.
     *
     * Les scores horaires détaillés (alimentant l'onglet « Détail
     * scoring » du volet droit) sont chargés à la demande au clic via
     * loadSiteScores (lazy).
     */
    async loadSites(){
        try {
            const r = await fetch('/api/map-bundle', {credentials:'same-origin'});
            if (!r.ok) throw new Error('map-bundle HTTP ' + r.status);
            const bundle = await r.json();

            // Watchdog de fraîcheur : bandeau si le scoring est périmé.
            this.scoringStale = bundle.scoring_status || null;

            this.sites = (bundle.sites || []).map(s => ({
                id: s.id, name: s.name, lat: s.lat, lng: s.lng,
                altitude: s.altitude, level: s.level, region: s.region,
                wind_dir_min: s.wind_dir_min, wind_dir_max: s.wind_dir_max,
                // user_scoring sera fusionné par loadScoringOverrides()
                user_scoring: null,
            }));

            // Index par site_id (équivalents des anciens this.dayQuality[id],
            // this.sunWindows[id] alimentés par /api/sites/{id}/scores)
            this.dayQuality = {};
            this.sunWindows = {};
            this.scoringSource = {};
            (bundle.sites || []).forEach(s => {
                this.dayQuality[s.id] = s.days || {};
                this.sunWindows[s.id] = s.sun_windows || {};
                this.scoringSource[s.id] = 'global';
            });

            // Agrégat global par jour (remplace buildDays). Le serveur a
            // pré-calculé best_status et green_slots cumulés sur tous les sites.
            this.days = (bundle.days_summary || []).map(d => ({
                raw: d.raw,
                bestStatus: d.best_status,
                greenSlots: d.green_slots,
                label: this._labelForDay(d.raw),
            }));
        } catch (e) {
            console.error('loadSites:', e);
            this.sites = []; this.days = []; this.dayQuality = {};
            this.sunWindows = {}; this.scoringSource = {};
        }

        // Phase 4 : sur-couche perso (badge + overrides scoring actif)
        if (this.authUser) await this.loadScoringOverrides();
        // Sites masqués : filtre les marqueurs + recalcule l'agrégat du jour
        if (this.authUser) await this.loadHiddenSites();

        this.renderMarkers();
    },

    /**
     * Récupère les sites masqués par l'utilisateur connecté + l'agrégat
     * journalier recalculé sans eux. Fusionne en mémoire :
     *  - hiddenSiteIds → filtre des marqueurs (_siteVisible)
     *  - days_summary  → remplace bestStatus / greenSlots du sélecteur de
     *    jour pour qu'il reflète uniquement les sites visibles.
     * Cf. FF_site_blacklist.md.
     */
    async loadHiddenSites(){
        try {
            const r = await fetch('/api/me/hidden-sites', {credentials:'same-origin'});
            if (!r.ok) return;
            const data = await r.json();
            this.hiddenSiteIds = data.hidden_site_ids || [];
            // Agrégat recalculé (null si aucun site masqué → on garde le global).
            if (Array.isArray(data.days_summary)) {
                const byRaw = {};
                data.days_summary.forEach(d => { byRaw[d.raw] = d; });
                this.days = this.days.map(day => {
                    const o = byRaw[day.raw];
                    return o ? { ...day, bestStatus: o.best_status, greenSlots: o.green_slots } : day;
                });
            }
        } catch (e) {
            // silencieux : sans l'overlay, l'utilisateur voit la carte globale
            console.warn('loadHiddenSites:', e);
        }
    },

    /**
     * Récupère les overrides de scoring perso pour l'utilisateur connecté
     * (typiquement 1-3 sites max). Fusionne en mémoire avec le bundle global.
     */
    async loadScoringOverrides(){
        try {
            const r = await fetch('/api/me/scoring-overrides', {credentials:'same-origin'});
            if (!r.ok) return;
            const data = await r.json();
            Object.entries(data.overrides || {}).forEach(([siteId, override]) => {
                const sid = parseInt(siteId, 10);
                const site = this.sites.find(s => s.id === sid);
                if (!site) return;
                // Badge user_scoring (active / inactive) — affiché sur le marker
                site.user_scoring = override.user_scoring || null;
                // Override des statuts journaliers si scoring perso ACTIF
                if (override.days && Object.keys(override.days).length > 0) {
                    this.dayQuality[sid] = override.days;
                    this.scoringSource[sid] = 'user';
                }
            });
        } catch (e) {
            // silencieux : on continue avec le bundle global, l'utilisateur
            // verra juste son scoring global au lieu de son perso
            console.warn('loadScoringOverrides:', e);
        }
    },

    /**
     * Formate un jour `d/m` en libellé court : « Aujourd'hui », « Demain »
     * ou jour de semaine abrégé + date.
     */
    _labelForDay(raw){
        const [d, mo] = raw.split('/');
        const dt = new Date(new Date().getFullYear(), parseInt(mo) - 1, parseInt(d));
        const now = new Date(), tom = new Date(now); tom.setDate(now.getDate() + 1);
        if (dt.toDateString() === now.toDateString()) return 'Aujourd\'hui';
        if (dt.toDateString() === tom.toDateString()) return 'Demain';
        return DAY_NAMES_SHORT[dt.getDay()] + ' ' + d + '/' + mo;
    },

    /**
     * Chargement lazy des scores horaires détaillés d'un site. Appelé au
     * clic sur un marker pour alimenter l'onglet « Détail scoring » du
     * volet droit. Le bundle global suffit pour les markers + couleurs.
     */
    async loadSiteScores(id){
        if (this.allScores[id]) return; // déjà chargé
        try{
            const r = await fetch(`/api/sites/${id}/scores`, {credentials:'same-origin'});
            const d = await r.json();
            this.allScores[id] = d.scores || [];
            // Les sunWindows et dayQuality sont déjà fournis par le bundle ;
            // /scores peut renvoyer du scoring perso actif (scoring_source='user')
            // — on met à jour scoringSource pour cohérence avec isUserScoringFor.
            if (d.scoring_source) this.scoringSource[id] = d.scoring_source;
        }
        catch(e){ this.allScores[id] = []; }
    },

    /** Retourne l'état du scoring perso de l'utilisateur sur un site :
     *  'active' | 'inactive' | null. Utilisé pour les badges du marker
     *  et le filtre « Mes sites ». Donnée fournie par /api/me/scoring-overrides. */
    userScoringFor(site){ return site?.user_scoring ?? null; },

    /** Vrai quand l'API a appliqué le scoring perso pour ce site. */
    isUserScoringFor(siteId){ return this.scoringSource[siteId] === 'user'; },

    // ── Tooltip onglet « Détail scoring » (cellules + cellules split) ──
    showVotingTip(ev, text){
        if(!text) return;
        const r = ev.target.getBoundingClientRect();
        this.votingTip = {
            visible: true,
            x: r.left + r.width / 2,
            y: r.top - 6,
            lines: String(text).split('\n').filter(Boolean),
        };
    },
    hideVotingTip(){ this.votingTip.visible = false; },

    selectDay(idx){
        this.selectedDayIdx=idx;
        this.renderMarkers();
        // Si le volet droit affiche un site : le jour a changé → MAJ contenu
        if(this.rightOpen && this.selectedFeature?.type==='site'){
            this.multimodelData=null; // endpoint multimodèle est par jour → invalidé
            if(this.rpTab==='synth' && this.chartData) this.$nextTick(()=>this.renderSynthese());
            if(this.rpTab==='score') this.$nextTick(()=>this.renderScoring());
            if(this.rpTab==='mod') this.loadModels();
        }
    },

    _statusVisible(st){
        if(st==='green') return this.showGreen;
        if(st==='orange') return this.showOrange;
        if(st==='red') return this.showRed;
        return true; // statut inconnu : toujours affiché
    },
    _siteVisible(site, status){
        // Sites masqués par l'utilisateur : jamais affichés (connecté only).
        if(this.authUser && this.hiddenSiteIds.includes(site.id)) return false;
        if(! this._statusVisible(status)) return false;
        // Filtre « Mes sites » : ne garde que ceux avec un scoring perso
        // (actif ou inactif). Désactivé si l'user n'est pas connecté.
        if(this.onlyMyScorings && this.authUser){
            if(this.userScoringFor(site) === null) return false;
        }
        return true;
    },
    toggleOnlyMyScorings(){
        this.onlyMyScorings = ! this.onlyMyScorings;
        this.renderMarkers();
    },
    renderMarkers(){
        if(!this._sitesCluster){
            this._sitesCluster = L.markerClusterGroup({
                chunkedLoading:true,
                disableClusteringAtZoom:11,
                maxClusterRadius:45,
                spiderfyOnMaxZoom:true,
                showCoverageOnHover:false,
                iconCreateFunction: function(cluster){
                    const count = cluster.getChildCount();
                    let cls = 'pg-cluster pg-cluster-sites';
                    if(count >= 20) cls += ' pg-cluster-lg';
                    else if(count >= 5) cls += ' pg-cluster-md';
                    return L.divIcon({html:'<span>'+count+'</span>',className:cls,iconSize:[36,36]});
                }
            });
            this.map.addLayer(this._sitesCluster);
        }
        const day=this.days[this.selectedDayIdx]?.raw;
        const toAdd = [];
        const oldMarkers = this.markers;
        this.markers = {};

        this.sites.forEach(site=>{
            const st=this.dayQuality[site.id]?.[day]?.status ?? 'unknown';
            if(! this._siteVisible(site, st)) return;

            const icon=L.divIcon({className:'',html:siteIconHtml(site,st),iconSize:[40,40],iconAnchor:[20,20]});
            const mk=L.marker([site.lat,site.lng],{icon})
                .bindTooltip(site.name,{permanent:false,direction:'top',offset:[0,-22]});
            mk.on('click',(e)=>{L.DomEvent.stopPropagation(e);this.clickSite(site,mk.getElement());});
            this.markers[site.id]=mk;
            toAdd.push(mk);
        });

        this._sitesCluster.clearLayers();
        if(toAdd.length) this._sitesCluster.addLayers(toAdd);

        if(this.selectedFeature?.type==='site' && this.markers[this.selectedFeature.id]){
            setTimeout(()=>this._markerEl(this.markers[this.selectedFeature.id])?.classList.add('selected'),50);
        }
    },

    // ── Clic sur un site → volet droit (onglet Synthèse par défaut) ──
    clickSite(site, markerEl){
        this._clearAllMarkerSelection();
        markerEl?.querySelector('.pg-site-marker')?.classList.add('selected');
        this.site=site;
        this.selectedFeature={type:'site',...site};
        this.rpTab='synth';
        this.scZoom='1j'; this.scMode='global';
        this.modVar='speed'; this.modZoom='1j'; this.modHidden={};
        this.closeWindRose();
        if(typeof destroyModelsChart==='function') destroyModelsChart();
        this.chartData=null; this.chartSite=null;
        this.multimodelData=null; this.multimodel5Data=null;
        this._multimodelByDay={};
        this.openRightPanel();
        // Chargements lazy en parallèle :
        //  - loadSiteScores : pour l'onglet « Détail scoring » (allScores[id])
        //    Plus rapide en cache miss qu'avant car le bundle a fait l'essentiel.
        //  - loadSiteChart  : pour l'onglet « Synthèse » (graphes confiance + plafond)
        this.loadSiteScores(site.id);
        this.loadSiteChart();
    },

    // ── Onglet « Synthèse pour la journée » (ancienne popup site) ──
    async loadSiteChart(){
        if(!this.site?.id) return;
        this.chartLoading=true;
        try{
            const res=await fetch(`/api/sites/${this.site.id}/chart`);
            this.chartData=await res.json();
            this.chartSite=this.chartData.site;
        }catch(e){console.error('chart load failed',e);}
        this.chartLoading=false;
        this.$nextTick(()=>this.renderSynthese());
    },
    // Refonte v2 : rendu canvas (nuages + vent double-axe). Cf.
    // map/_partials/scripts/panel-synthese.
    renderSynthese(){
        if(!this.chartData) return;
        this.$nextTick(()=>{
            buildSynthClouds(this);
            buildSynthWind(this);
        });
    },

    // ── Onglet « Synthèse » (refonte v2) : helpers + getters ─────
    get synthDay(){ return this.days[this.selectedDayIdx]?.raw; },
    // Données horaires du jour, clippées à la fenêtre solaire (axe « daylight »).
    get synthDayData(){
        const day=this.synthDay;
        const arr=this.chartData?.days?.[day]??[];
        const win=this.chartData?.sun_windows?.[day];
        if(win && win.start_hour!=null && win.end_hour!=null){
            return arr.filter(h=>{const hh=parseInt(h.hour,10);return hh>=win.start_hour && hh<=win.end_hour;});
        }
        return arr;
    },
    get synthQuality(){
        const id=this.site?.id;
        return (id!=null && this.dayQuality[id]) ? (this.dayQuality[id][this.synthDay] ?? null) : null;
    },
    get synthScore(){ const q=this.synthQuality; return q && q.viability!=null ? Math.round(q.viability) : null; },
    get synthStatus(){ return this.synthQuality?.status ?? 'unknown'; },
    get synthHero(){
        const map={
            green:  {label:'Site favorable',         icon:'ti-check',          dot:'#16a34a', text:'#4ade80', bg:'rgba(22,163,74,0.1)',  border:'rgba(22,163,74,0.24)'},
            orange: {label:'Conditions incertaines', icon:'ti-alert-triangle', dot:'#d97706', text:'#fbbf24', bg:'rgba(217,119,6,0.1)',  border:'rgba(217,119,6,0.24)'},
            red:    {label:'Site défavorable',       icon:'ti-x',              dot:'#dc2626', text:'#f87171', bg:'rgba(220,38,38,0.1)',  border:'rgba(220,38,38,0.24)'},
            unknown:{label:'Données indisponibles',  icon:'ti-help',           dot:'#6b7280', text:'#9ca3af', bg:'rgba(255,255,255,0.04)', border:'rgba(255,255,255,0.08)'},
        };
        return map[this.synthStatus] ?? map.unknown;
    },
    // Plus longue plage verte consécutive de la journée (fenêtre optimale).
    get synthWindow(){
        let best=null, cur=null;
        for(const h of this.synthDayData){
            const hh=parseInt(h.hour,10);
            if(h.status==='green'){
                cur = cur ? {start:cur.start, end:hh} : {start:hh, end:hh};
                if(!best || (cur.end-cur.start) > (best.end-best.start)) best={...cur};
            } else cur=null;
        }
        return best;
    },
    get synthWindowLabel(){
        const w=this.synthWindow;
        if(!w) return 'aucun créneau favorable';
        return `${String(w.start).padStart(2,'0')}h – ${String(w.end+1).padStart(2,'0')}h`;
    },
    // Moyenne convergence/total des modèles sur la journée (depuis allScores).
    get synthModels(){
        const id=this.site?.id, day=this.synthDay;
        const arr=(this.allScores[id]||[]).filter(s=>s.day===day);
        const conv=arr.map(s=>s.models_conv).filter(v=>v!=null);
        const tot =arr.map(s=>s.models_count).filter(v=>v!=null);
        if(!conv.length || !tot.length) return null;
        const avg=a=>Math.round(a.reduce((x,y)=>x+y,0)/a.length);
        return { conv:avg(conv), total:avg(tot) };
    },
    // Créneau le plus proche de 13h pour la mini-rose.
    get synthMiniRose(){
        const arr=this.synthDayData;
        if(!arr.length) return null;
        let best=arr[0], bd=99;
        for(const h of arr){ const d=Math.abs(parseInt(h.hour,10)-13); if(d<bd){bd=d;best=h;} }
        const dir=best.wind_dir;
        return {
            hour: parseInt(best.hour,10)+'h',
            dir,
            dirCompass: dir!=null ? degToCompass(dir) : '',
            avg: best.wind_avg, gust: best.wind_max,
            inAxis: dir!=null ? this._inAxis(dir) : false,
        };
    },
    // Direction dans l'axe favorable du site (gère le passage par le Nord).
    _inAxis(dir){
        const a=this.selectedFeature?.wind_dir_min, b=this.selectedFeature?.wind_dir_max;
        if(a==null || b==null || dir==null) return false;
        return a<=b ? (dir>=a && dir<=b) : (dir>=a || dir<=b);
    },

    // ── Rose des vents animée (modale, refonte v2) ───────────────
    openWindRose(){
        if(!this.synthDayData.length) return;
        this.windRoseOpen=true;
        this.$nextTick(()=>{ if(typeof wrInit==='function') wrInit(this); });
    },
    closeWindRose(){ this.wrStopPlay(); this.windRoseOpen=false; },
    wrStep(delta){
        const n=this._wrData?.length||0; if(!n) return;
        this.wrIdx=Math.max(0,Math.min(n-1,this.wrIdx+delta));
        if(typeof wrRender==='function') wrRender(this);
    },
    wrSetIdx(i){ this.wrIdx=i; if(typeof wrRender==='function') wrRender(this); },
    wrTogglePlay(){
        if(this.wrPlaying){ this.wrStopPlay(); return; }
        this.wrPlaying=true;
        this._wrTimer=setInterval(()=>{
            const n=this._wrData?.length||0; if(!n){ this.wrStopPlay(); return; }
            this.wrIdx=(this.wrIdx+1)%n;
            if(typeof wrRender==='function') wrRender(this);
        }, 650);
    },
    wrStopPlay(){ if(this._wrTimer){ clearInterval(this._wrTimer); this._wrTimer=null; } this.wrPlaying=false; },

    // ── Popup flottante balise / station (refonte v2) ────────────
    openFeaturePopup(type, id, latlng){
        // Re-clic sur la même feature déjà ouverte → ne rien faire (évite
        // que la popup saute/se recharge inutilement).
        if(this.featurePopup.open && this.featurePopup.type === type && this.featurePopup.id === id) return;
        this.featurePopup = { open:true, type, id, tab:'rose' };
        this._fpAnchor = latlng;
        this._fpPositioned = false;   // positionnée une seule fois après le 1er rendu de contenu
        this.fpReady = false;         // masquée tant que pas positionnée (pas de flash/saut)
        this.fpWindow = 24;
        this.fpData = null;
        this.fpComparData = null; this.fpComparVar = 'wind_mean';
        this.loadFeaturePopup();
    },
    closeFeaturePopup(){
        this.featurePopup = { open:false, type:null, id:null, tab:'rose' };
        this.fpData = null; this._fpAnchor = null; this._fpPositioned = false; this.fpReady = false;
    },
    async loadFeaturePopup(){
        const { type, id } = this.featurePopup;
        if(id == null) return;
        this.fpLoading = true;
        try{
            const url = type === 'balise'
                ? `/api/balises/${id}/history?window=${this.fpWindow}`
                : `/api/weather-stations/${id}/detail?window=${this.fpWindow}`;
            const r = await fetch(url, {credentials:'same-origin'});
            this.fpData = await r.json();
        }catch(e){ console.error('feature popup load failed', e); this.fpData = null; }
        this.fpLoading = false;
        // Positionnement UNE seule fois (au 1er rendu de contenu). Les
        // changements d'onglet/période ne repositionnent jamais → la popup
        // ne saute plus. Seul le pan/zoom la recale sur le marqueur.
        this.$nextTick(()=>{
            this.renderFeaturePopup();
            if(!this._fpPositioned){ this.positionFeaturePopup(); this._fpPositioned = true; this.fpReady = true; }
        });
    },
    setFpWindow(w){ if(w === this.fpWindow) return; this.fpWindow = w; this.loadFeaturePopup(); },
    setFpTab(tab){
        this.featurePopup.tab = tab;
        this.$nextTick(()=>{
            this.renderFeaturePopup();
            if(tab === 'evo'){
                if(!this.fpComparData && !this.fpComparLoading) this.loadFpCompar();
                else this.renderFpCompar();
            }
        });
    },
    // ── Onglet Évolution (mesures vs consensus) ──
    async loadFpCompar(){
        const { type, id } = this.featurePopup;
        if(id == null) return;
        this.fpComparLoading = true;
        try{
            const url = type === 'balise'
                ? `/api/balises/${id}/comparison`
                : `/api/weather-stations/${id}/comparison`;
            const r = await fetch(url, {credentials:'same-origin'});
            this.fpComparData = await r.json();
        }catch(e){ console.error('comparison load failed', e); this.fpComparData = null; }
        this.fpComparLoading = false;
        this.$nextTick(()=>this.renderFpCompar());
    },
    renderFpCompar(){
        if(this.featurePopup.tab !== 'evo') return;
        const d = this.fpComparData; if(!d) return;
        const v = d.vars?.[this.fpComparVar]; if(!v) return;
        drawComparChart(v.measure, v.consensus, {
            unit:v.unit, min:v.min, max:v.max, color:v.color,
            nowStep:d.now_step, nowIdx:d.now_idx, days:d.days,
        });
    },
    setFpComparVar(k){ this.fpComparVar = k; this.$nextTick(()=>this.renderFpCompar()); },
    get fpComparVars(){
        if(this.featurePopup.type === 'station') return [
            {key:'wind_mean',label:'Vent moyen'}, {key:'wind_gust',label:'Rafales'},
            {key:'temp',label:'Température'}, {key:'humidity',label:'Humidité'}, {key:'pressure',label:'Pression'},
        ];
        return [{key:'wind_mean',label:'Vent moyen'}, {key:'wind_gust',label:'Rafales'}, {key:'wind_dir',label:'Direction'}];
    },
    get fpComparVarLabel(){ return (this.fpComparVars.find(v => v.key === this.fpComparVar) || {}).label || ''; },
    renderFeaturePopup(){
        if(this.featurePopup.tab === 'rose' && this.fpData){
            const readings = (this.fpData.readings || []).map(r => ({ dir:r.wind_direction, spd:r.wind_speed_avg }));
            drawFreqRose(readings, this.fpCurrent);
        }
    },
    positionFeaturePopup(){
        if(!this.featurePopup.open || !this._fpAnchor || !this.map) return;
        if(!window.AppShell.isDesktop()) return; // mobile = plein écran (CSS)
        const el = document.getElementById('feature-popup');
        const mapEl = document.getElementById('map');
        if(!el || !mapEl) return;
        const pt = this.map.latLngToContainerPoint(this._fpAnchor);
        const rect = mapEl.getBoundingClientRect();
        const ax = rect.left + pt.x, ay = rect.top + pt.y;
        const W = el.offsetWidth || 360, H = el.offsetHeight || 320;
        const off = 26, gap = 14, m = 8;
        let left = ax - W/2;
        left = Math.max(m, Math.min(left, window.innerWidth - W - m));
        // Ancrage SOUS le marqueur par défaut (le haut reste stable quand le
        // contenu change de hauteur) ; flip AU-DESSUS si débordement bas.
        let top = ay + off;
        if(top + H > window.innerHeight - m){
            const above = ay - H - gap;
            top = above >= m ? above : Math.max(m, window.innerHeight - H - m);
        }
        el.style.left = left + 'px';
        el.style.top  = top + 'px';
    },
    get fpName(){
        return this.featurePopup.type === 'balise'
            ? (this.fpData?.balise?.name ?? '')
            : (this.fpData?.station?.name ?? '');
    },
    get fpAltitude(){
        return this.featurePopup.type === 'balise'
            ? (this.fpData?.balise?.altitude_m ?? null)
            : (this.fpData?.station?.altitude_m ?? null);
    },
    get fpNetworkLabel(){
        if(this.featurePopup.type === 'balise'){
            const src = this.fpData?.balise?.source, ext = this.fpData?.balise?.external_id;
            if(!src) return '';
            return src.charAt(0).toUpperCase() + src.slice(1) + (ext ? ' #' + ext : '');
        }
        const net = this.fpData?.station?.network ?? '';
        const map = {meteofrance_synop:'Météo-France SYNOP', mf:'Météo-France', metar:'METAR', infoclimat:'Infoclimat'};
        return map[net] ?? (net ? net.toUpperCase() : '');
    },
    get fpCurrent(){
        const l = this.fpData?.latest;
        if(!l) return { dir:null, mean:null, gust:null };
        return { dir:l.wind_direction, mean:l.wind_speed_avg, gust:l.wind_speed_max };
    },
    get fpRoseStats(){
        const readings = (this.fpData?.readings || []).filter(r => r.wind_direction != null);
        if(!readings.length) return null;
        const sect = new Array(36).fill(0);
        readings.forEach(r => { sect[Math.floor((((r.wind_direction%360)+360)%360)/10)%36]++; });
        const di = sect.indexOf(Math.max(...sect));
        const dom = di*10 + 5;
        return { domDir:dom, domCard:degToCompass(dom), domPct:Math.round(sect[di]/readings.length*100) };
    },
    get fpFreshness(){ return formatAge(this.fpData?.latest?.read_at ?? this.fpData?.latest?.observed_at); },
    get fpIsLive(){
        const ts = this.fpData?.latest?.read_at ?? this.fpData?.latest?.observed_at;
        return ts ? (Date.now() - new Date(ts).getTime())/60000 < 30 : false;
    },
    _spdColor(s){ return s == null ? '#9ca3af' : s < 12 ? '#4ade80' : s < 22 ? '#4ea8e0' : s < 32 ? '#fbbf24' : '#f87171'; },
    get chartHasData(){
        const day=this.days[this.selectedDayIdx]?.raw;
        return (this.chartData?.days?.[day]?.length ?? 0) > 0;
    },
    get chartDayCount(){
        const day=this.days[this.selectedDayIdx]?.raw;
        return this.chartData?.days?.[day]?.length ?? 0;
    },
    get panelSunWindow(){
        if(!this.chartData) return null;
        const day=this.days[this.selectedDayIdx]?.raw;
        return this.chartData.sun_windows?.[day] ?? null;
    },
    get siteLevelLabel(){
        const lv=this.selectedFeature?.level;
        return {debutant:'Débutant', intermediaire:'Intermédiaire', confirme:'Confirmé'}[lv] ?? (lv || '');
    },
    get siteOrientationLabel(){
        const a=this.selectedFeature?.wind_dir_min, b=this.selectedFeature?.wind_dir_max;
        if(a==null || b==null) return '';
        // Direction médiane de la plage favorable (gère le passage par le Nord)
        const mid = a<=b ? (a+b)/2 : ((a+b+360)/2)%360;
        return `${COMPASS_8[Math.round(mid/45)%8]} (${Math.round(a)}–${Math.round(b)}°)`;
    },
    get chartConfidence(){
        const day=this.days[this.selectedDayIdx]?.raw;
        const arr=(this.chartData?.days?.[day]??[]).map(h=>h.confidence).filter(c=>c!=null);
        if(!arr.length) return null;
        return Math.round(arr.reduce((a,b)=>a+b,0)/arr.length);
    },
    get chartConfidenceColor(){ return this._conformityColor(this.chartConfidence); },

    // ── Onglets du volet droit ───────────────────────────────────
    // Refonte v2 : onglets synth | score | mod. Synthèse rendue en canvas ;
    // Scoring (Phase 2) et Modèles (Phase 3) sont des placeholders pour
    // l'instant — les méthodes loadMultimodel/votingDays/renderCharts* sont
    // conservées en vue de leur ré-câblage.
    async setRpTab(tab){
        this.rpTab=tab;
        if(tab==='synth' && this.chartData){
            this.$nextTick(()=>this.renderSynthese());
        }else if(tab==='score'){
            if(this.site?.id!=null && !this.allScores[this.site.id]) await this.loadSiteScores(this.site.id);
            this.$nextTick(()=>this.renderScoring());
        }else if(tab==='mod'){
            await this.loadModels();
        }
    },

    // ── Onglet « Modèles » (refonte v2) ──────────────────────────
    get modelsPayload(){ return this.modZoom==='1j' ? this.multimodelData : this.multimodel5Data; },
    get modelsLoading(){ return this.modZoom==='1j' ? this.multimodelLoading : this.multimodel5Loading; },
    get modVarObj(){ return MOD_VARS.find(v=>v.key===this.modVar) || MOD_VARS[0]; },
    async loadModels(){
        if(this.modZoom==='1j') await this.loadMultimodel();
        else await this.loadFiveDays();
        this.$nextTick(()=>this.renderModels());
    },
    renderModels(){
        if(!this.modelsPayload) return;
        this.$nextTick(()=>renderModelsTab(this));
    },
    setModVar(k){ this.modVar=k; this.renderModels(); },
    setModZoom(z){ if(z===this.modZoom) return; this.modZoom=z; this.loadModels(); },
    modelsDayStep(d){
        if(this.modZoom==='5j') return;
        const i=Math.max(0,Math.min(4,this.selectedDayIdx+d));
        if(i!==this.selectedDayIdx) this.selectDay(i);
    },
    toggleModel(id){ this.modHidden[id]=!this.modHidden[id]; this.renderModels(); },

    // ── Onglet « Scoring » (refonte v2) ──────────────────────────
    renderScoring(){
        if(this.site?.id==null) return;
        this.$nextTick(()=>buildScoringTab(this));
    },
    setScZoom(z){ this.scZoom=z; this.renderScoring(); },
    setScMode(m){ if(m==='perso' && !this.scoringHasPerso) return; this.scMode=m; this.renderScoring(); },
    scoringDayStep(d){
        if(this.scZoom==='5j') return;
        const i=Math.max(0,Math.min(4,this.selectedDayIdx+d));
        if(i!==this.selectedDayIdx) this.selectDay(i);
    },
    get scoringHasPerso(){ return !!(this.authUser && this.selectedFeature?.user_scoring==='active'); },
    get scoringPilotName(){ return this.authUser?.display_name ?? this.authUser?.name ?? 'Perso'; },

    // ── Onglet « Détail du scoring (voting logic) · 5 jours » ────
    // Construit la structure d'affichage : 5 tableaux (1 par jour),
    // chaque tableau = 5 paramètres + 1 ligne statut en bas, colonnes =
    // heures de la fenêtre solaire. Les couleurs viennent de
    // score.detail.<param>.color, calculées par ScoringService.
    get votingHasData(){
        const id = this.site?.id;
        if(!id) return false;
        return (this.allScores[id]?.length ?? 0) > 0;
    },
    get votingDays(){
        const id = this.site?.id;
        if(!id) return [];
        const scores = this.allScores[id] || [];
        const windows = this.sunWindows[id] || {};
        const slice = this.days.slice(0, 5);
        const PARAM_ROWS = [
            {key:'wind_dir',   label:'Direction'},
            {key:'wind_speed', label:'Vitesse'},
            {key:'wind_gust',  label:'Rafales'},
            {key:'precip',     label:'Précipitations'},
            {key:'cloud_base', label:'Plafond'},
        ];
        return slice.map(d => {
            const dayScores = scores.filter(s => s.day === d.raw);
            const win = windows[d.raw];
            // Reconstruit la liste d'heures attendue (fenêtre solaire) ;
            // fallback : heures réellement présentes dans dayScores.
            let hours;
            if(win && win.start_hour != null && win.end_hour != null){
                hours = [];
                for(let h = win.start_hour; h <= win.end_hour; h++) hours.push(h);
            }else{
                hours = [...new Set(dayScores.map(s => parseInt(s.hour, 10)))].sort((a,b)=>a-b);
            }
            // Indexe les scores du jour par heure
            const byHour = {};
            dayScores.forEach(s => { byHour[parseInt(s.hour, 10)] = s; });

            const rows = PARAM_ROWS.map(p => ({
                key: p.key,
                label: p.label,
                cells: hours.map(h => this._votingCell(byHour[h], p.key)),
            }));
            // Ligne statut global en bas
            rows.push({
                key: 'status',
                label: 'Statut global',
                cells: hours.map(h => this._votingCell(byHour[h], 'status')),
            });

            const hint = win
                ? `${String(win.start_hour).padStart(2,'0')}h → ${String(win.end_hour).padStart(2,'0')}h`
                : '';

            return { raw:d.raw, label:d.label, hint, hours, rows };
        });
    },
    _votingCell(score, key){
        if(!score) return { color:'na', title:'Aucune donnée' };
        if(key === 'status'){
            const c  = ['green','orange','red'].includes(score.status)        ? score.status        : 'na';
            const cg = ['green','orange','red'].includes(score.status_global) ? score.status_global : null;
            const cell = { color: c, title: this._votingStatusTitle(score, false) };
            if(cg !== null){
                cell.colorGlobal = cg;
                cell.titleGlobal = this._votingStatusTitle(score, true);
            }
            return cell;
        }
        const d = score.detail?.[key];
        if(!d) return { color:'na', title:'Donnée indisponible' };
        const c   = ['green','orange','red'].includes(d.color)        ? d.color        : 'na';
        const cg  = ['green','orange','red'].includes(d.color_global)  ? d.color_global : null;
        // Cellule split diagonale dès que l'API fournit `color_global` —
        // donc dès qu'un scoring perso est appliqué sur ce site. Même si
        // perso et global concluent à la même couleur, on garde la
        // diagonale visible (séparateur clair côté CSS) pour signaler
        // d'un coup d'œil que c'est ton scoring qui s'applique.
        const cell = { color: c, title: this._votingParamTitle(key, score, d, false) };
        if(cg !== null){
            cell.colorGlobal = cg;
            cell.titleGlobal = this._votingParamTitle(key, score, d, true);
        }
        return cell;
    },
    _votingStatusTitle(s, forGlobal = false){
        const st  = forGlobal ? s.status_global : s.status;
        const lbl = {green:'OK', orange:'Prudence', red:'Éliminatoire', unknown:'—'}[st] || st;
        const tag = forGlobal ? ' · global' : (s.status_global != null ? ' · perso' : '');
        return `${s.hour} · ${lbl} · confiance ${s.confidence ?? '—'}%${tag}`;
    },
    /** @param {boolean} forGlobal — vrai si on veut décrire la couleur globale (sinon la perso). */
    _votingParamTitle(key, s, d, forGlobal){
        const hour  = s.hour;
        const color = forGlobal ? d.color_global : d.color;
        const lbl   = {green:'OK', orange:'Prudence', red:'Éliminatoire'}[color] || '—';
        const suffix = ` · ${forGlobal ? 'global' : 'perso'} : ${lbl}`;
        switch(key){
            case 'wind_dir':
                return `${hour} · direction ${d.consensus != null ? Math.round(d.consensus) + '°' : '—'}` + suffix;
            case 'wind_speed':
                return `${hour} · vent moyen ${d.consensus != null ? Number(d.consensus).toFixed(1) + ' km/h' : '—'}` + suffix;
            case 'wind_gust':
                return `${hour} · rafales ${d.consensus != null ? Number(d.consensus).toFixed(1) + ' km/h' : '—'}` + suffix;
            case 'precip':
                return `${hour} · pluie ${d.consensus != null ? Number(d.consensus).toFixed(1) + ' mm/h' : '—'}` + suffix;
            case 'cloud_base':
                return `${hour} · plafond ${d.consensus != null ? d.consensus + ' m' : '—'}` + suffix;
        }
        return hour + suffix;
    },

    // ── Conformité / libellés ────────────────────────────────────
    get conformityColor(){ return this._conformityColor(this.multimodelData?.conformity_pct); },
    get conformity5Color(){ return this._conformityColor(this.multimodel5Data?.conformity_pct); },
    get fiveDaysRangeLabel(){
        const slice=this.days.slice(0,5);
        if(!slice.length) return '—';
        return slice[0].raw+' → '+slice[slice.length-1].raw;
    },
    _conformityColor(c){
        if(c==null) return '#6b7280';
        if(c>=75) return '#22c55e';
        if(c>=50) return '#f59e0b';
        return '#ef4444';
    },

    // ── Chargement des modèles météo ─────────────────────────────
    async loadMultimodel(){
        if(!this.site?.id) return;
        const day=this.days[this.selectedDayIdx]?.raw;
        if(!day) return;
        const ymd=this._dayRawToYmd(day);
        // Cache mémoire : si on a déjà ce jour, on bypass le fetch.
        if(this._multimodelByDay[ymd]){
            this.multimodelData=this._multimodelByDay[ymd];
            this.$nextTick(()=>this.renderCharts());
            return;
        }
        this.multimodelData=null;
        this.multimodelLoading=true;
        try{
            const r=await fetch(`/api/sites/${this.site.id}/multimodel?day=${ymd}&period=24h`);
            if(!r.ok) throw new Error('HTTP '+r.status);
            const payload=await r.json();
            this._multimodelByDay[ymd]=payload;
            this.multimodelData=payload;
        }catch(e){console.error('multimodel load failed',e);}
        this.multimodelLoading=false;
        this.$nextTick(()=>this.renderCharts());
    },
    // Charge les 5 jours en parallèle (en sautant ceux déjà en cache) et
    // fusionne en une structure unique avec hours = [0..119] (5 jours × 24h).
    async loadFiveDays(){
        if(!this.site?.id || !this.days.length) return;
        this.multimodel5Loading=true;
        this.multimodel5Data=null;
        try{
            const slice=this.days.slice(0,5);
            const ymds=slice.map(d=>this._dayRawToYmd(d.raw));
            // Ne fetch que les jours manquants du cache.
            const missing=ymds.filter(ymd=>!this._multimodelByDay[ymd]);
            if(missing.length){
                const reqs=missing.map(ymd=>
                    fetch(`/api/sites/${this.site.id}/multimodel?day=${ymd}&period=24h`).then(r=>{
                        if(!r.ok) throw new Error('HTTP '+r.status);
                        return r.json().then(payload=>{
                            this._multimodelByDay[ymd]=payload;
                            return payload;
                        });
                    })
                );
                await Promise.all(reqs);
            }
            const responses=ymds.map(ymd=>this._multimodelByDay[ymd]);
            const merged={
                viewMode:'fivedays',
                site:responses[0].site,
                models:responses[0].models,
                hours:[], data:{}, consensus:{},
                day_separators:[], day_labels:[], sun_windows_by_day:[],
            };
            responses.forEach((resp,dayIdx)=>{
                const offset=dayIdx*24;
                for(let h=0;h<24;h++){
                    const flat=offset+h;
                    merged.hours.push(flat);
                    if(resp.data && resp.data[h]) merged.data[flat]=resp.data[h];
                    if(resp.consensus && resp.consensus[h]) merged.consensus[flat]=resp.consensus[h];
                }
                if(dayIdx<responses.length-1) merged.day_separators.push((dayIdx+1)*24);
                merged.day_labels.push({offset, label:slice[dayIdx]?.label||slice[dayIdx]?.raw||'', raw:slice[dayIdx]?.raw});
                merged.sun_windows_by_day.push({offset, start:resp.sun_window?.start_hour??null, end:resp.sun_window?.end_hour??null});
            });
            const conformities=responses.map(r=>r.conformity_pct).filter(c=>c!=null);
            merged.conformity_pct=conformities.length?Math.round(conformities.reduce((a,b)=>a+b,0)/conformities.length):null;
            this.multimodel5Data=merged;
        }catch(e){console.error('5-day load failed',e);}
        this.multimodel5Loading=false;
        this.$nextTick(()=>this.renderCharts5());
    },
    renderCharts(){
        if(!this.multimodelData) return;
        CHART_CONFIGS.forEach(cfg=>{
            if(this.chartCollapsed[cfg.id]) return;
            const fn=cfg.type==='bar'?buildBarChart:buildLineChart;
            fn('svg-'+cfg.id, cfg, this.multimodelData, this);
        });
    },
    renderCharts5(){
        if(!this.multimodel5Data) return;
        CHART_CONFIGS.forEach(cfg=>{
            if(this.chartCollapsed5[cfg.id]) return;
            const fn=cfg.type==='bar'?buildBarChart:buildLineChart;
            fn('svg5-'+cfg.id, cfg, this.multimodel5Data, this);
        });
    },
    toggleChart(id){
        this.chartCollapsed[id]=!this.chartCollapsed[id];
        if(!this.chartCollapsed[id]){
            this.$nextTick(()=>{
                const cfg=CHART_CONFIGS.find(c=>c.id===id);
                if(!cfg || !this.multimodelData) return;
                (cfg.type==='bar'?buildBarChart:buildLineChart)('svg-'+id, cfg, this.multimodelData, this);
            });
        }
    },
    toggleChart5(id){
        this.chartCollapsed5[id]=!this.chartCollapsed5[id];
        if(!this.chartCollapsed5[id]){
            this.$nextTick(()=>{
                const cfg=CHART_CONFIGS.find(c=>c.id===id);
                if(!cfg || !this.multimodel5Data) return;
                (cfg.type==='bar'?buildBarChart:buildLineChart)('svg5-'+id, cfg, this.multimodel5Data, this);
            });
        }
    },
    _dayRawToYmd(raw){
        const [d,m]=raw.split('/');
        const yyyy=new Date().getFullYear();
        return `${yyyy}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    },

    // ── Balises météo ────────────────────────────────────────────
    async loadBalises(){
        // Dédup : si une requête est déjà en vol (réseau lent), on l'annule
        // pour éviter une race condition (réponse 2 qui arrive avant la 1
        // → données périmées affichées).
        if(this._balisesAbort) this._balisesAbort.abort();
        this._balisesAbort=new AbortController();
        try{
            const r=await fetch('/api/balises',{signal:this._balisesAbort.signal});
            if(!r.ok) throw new Error('HTTP '+r.status);
            this.balises=await r.json();
            this.renderBalises();
        }catch(e){
            if(e.name!=='AbortError') console.warn('loadBalises failed',e);
        }
    },
    /** Source d'une balise, normalisée vers une clé connue de
     *  BALISE_NETWORKS. Un fournisseur inattendu (ex. holfuy à venir)
     *  est rangé dans pioupiou par défaut pour rester visible. */
    _baliseNetworkKey(b){
        const known = this.BALISE_NETWORKS.map(n => n.key);
        return known.includes(b.source) ? b.source : 'pioupiou';
    },
    _ensureNetworkLayer(key){
        if(!this._balisesLayers[key]){
            this._balisesLayers[key] = L.markerClusterGroup({
                chunkedLoading:true,
                disableClusteringAtZoom:13,
                maxClusterRadius:50,
                showCoverageOnHover:false,
                iconCreateFunction: function(cluster){
                    const count = cluster.getChildCount();
                    let cls = 'pg-cluster pg-cluster-balises';
                    if(count >= 20) cls += ' pg-cluster-lg';
                    else if(count >= 5) cls += ' pg-cluster-md';
                    return L.divIcon({html:'<span>'+count+'</span>',className:cls,iconSize:[32,32]});
                }
            });
            if(this.networksVisible[key]) this._balisesLayers[key].addTo(this.map);
        }
        return this._balisesLayers[key];
    },
    balisesCountByNetwork(key){
        let n = 0;
        for(const b of this.balises){
            if(this._baliseNetworkKey(b) === key) n++;
        }
        return n;
    },
    renderBalises(){
        if(!this.map) return;
        const byNet = {};
        this._balisesMarkers = {};

        for(const b of this.balises){
            const netKey = this._baliseNetworkKey(b);
            const icon   = L.icon({iconUrl:baliseIconUrl(b.reading), iconSize:[40,40], iconAnchor:[20,20]});
            const m = L.marker([b.lat,b.lng],{icon})
                .bindTooltip(baliseTooltipHtml(b),{direction:'top',offset:[0,-22],opacity:.95});
            m._netKey = netKey;
            m.on('click',(e)=>{L.DomEvent.stopPropagation(e);this.clickBalise(b.id,m.getLatLng());});
            this._balisesMarkers[b.id] = m;
            if(!byNet[netKey]) byNet[netKey] = [];
            byNet[netKey].push(m);
        }

        for(const net of this.BALISE_NETWORKS){
            const layer = this._ensureNetworkLayer(net.key);
            layer.clearLayers();
            if(byNet[net.key]?.length) layer.addLayers(byNet[net.key]);
        }
    },
    /** Affiche / masque un réseau de balises sans toucher aux autres. */
    toggleNetwork(key){
        if(!(key in this.networksVisible)) return;
        this.networksVisible[key] = !this.networksVisible[key];
        const layer = this._balisesLayers[key];
        if(!layer || !this.map) return;
        if(this.networksVisible[key]) layer.addTo(this.map);
        else this.map.removeLayer(layer);
    },

    // ── Clic sur une balise → volet droit (relevés + historique du jour) ──
    // Refonte v2 : le clic balise ouvre une popup flottante (cohabite avec
    // le drawer site), au lieu du drawer droit. _fpAnchor = position marqueur.
    clickBalise(id, latlng){
        this._baliseObj = this.balises.find(x => x.id === id) || null;
        this.openFeaturePopup('balise', id, latlng);
    },
    async loadBaliseHistory(id){
        this.baliseLoading=true;
        try{
            const res=await fetch(`/api/balises/${id}/history`);
            this.baliseData=await res.json();
        }catch(e){console.error('balise history failed',e);}
        this.baliseLoading=false;
        this.$nextTick(()=>this.renderBaliseCharts());
    },
    renderBaliseCharts(){
        if(!this.baliseData) return;
        buildBaliseRoseSVG(this.baliseData);
        buildBaliseChartSVG(this.baliseData);
    },
    get baliseFreshness(){
        return formatAge(this.baliseData?.latest?.read_at ?? this._baliseObj?.reading?.read_at);
    },
    get baliseTrendArrow(){
        const t=this._baliseObj?.reading?.trend ?? 0;
        return ['↓↓','↓','→','↑','↑↑'][t+2] ?? '→';
    },
    get baliseTrendColor(){
        const t=this._baliseObj?.reading?.trend ?? 0;
        if(t>=1)  return '#fb923c';
        if(t<=-1) return '#60a5fa';
        return '#9ca3af';
    },
    get baliseReseauLabel(){
        return (this.baliseData?.balise?.source ?? this.selectedFeature?.source ?? '').toUpperCase();
    },

    // ── Stations météo ──────────────────────────────────────────
    _anyStationNetworkOn(){
        return Object.values(this.stationNetworksVisible).some(v => v);
    },
    _removeAllStationLayers(){
        for(const key of Object.keys(this._stationsLayers)){
            if(this.map.hasLayer(this._stationsLayers[key])){
                this.map.removeLayer(this._stationsLayers[key]);
            }
        }
    },
    _addVisibleStationLayers(){
        for(const key of Object.keys(this._stationsLayers)){
            if(this.stationNetworksVisible[key] && !this.map.hasLayer(this._stationsLayers[key])){
                this._stationsLayers[key].addTo(this.map);
            }
        }
    },
    stationZoomOk(){ return this.map && this.map.getZoom() >= this.STATION_MIN_ZOOM; },
    async loadStations(){
        if(!this._anyStationNetworkOn() || !this.stationZoomOk()){
            this._removeAllStationLayers();
            return;
        }
        if(this._stationsAbort) this._stationsAbort.abort();
        this._stationsAbort=new AbortController();
        try{
            const b = this.map.getBounds();
            const url = '/api/weather-stations?bbox=' + [
                b.getSouth().toFixed(4),
                b.getNorth().toFixed(4),
                b.getWest().toFixed(4),
                b.getEast().toFixed(4),
            ].join(',');
            const r=await fetch(url,{signal:this._stationsAbort.signal});
            if(!r.ok) throw new Error('HTTP '+r.status);
            this.weatherStations=await r.json();
            this.renderStations();
        }catch(e){
            if(e.name!=='AbortError') console.warn('loadStations failed',e);
        }
    },
    _stationNetworkKey(s){
        const known = this.STATION_NETWORKS.map(n => n.key);
        return known.includes(s.network) ? s.network : 'mf';
    },
    _ensureStationLayer(key){
        if(!this._stationsLayers[key]){
            this._stationsLayers[key] = L.markerClusterGroup({
                chunkedLoading:true,
                disableClusteringAtZoom:12,
                maxClusterRadius:55,
                showCoverageOnHover:false,
                iconCreateFunction: function(cluster){
                    const count = cluster.getChildCount();
                    let cls = 'pg-cluster pg-cluster-stations';
                    if(count >= 20) cls += ' pg-cluster-lg';
                    else if(count >= 5) cls += ' pg-cluster-md';
                    return L.divIcon({html:'<span>'+count+'</span>',className:cls,iconSize:[30,30]});
                }
            });
            if(this.stationNetworksVisible[key]) this._stationsLayers[key].addTo(this.map);
        }
        return this._stationsLayers[key];
    },
    stationsCountByNetwork(key){
        let n = 0;
        for(const s of this.weatherStations){
            if(this._stationNetworkKey(s) === key) n++;
        }
        return n;
    },
    renderStations(){
        if(!this.map) return;
        const byNet = {};
        this._stationsMarkers = {};

        for(const s of this.weatherStations){
            const netKey = this._stationNetworkKey(s);
            const fKey   = stationFreshnessKey(s.reading?.observed_at);
            const icon   = L.icon({iconUrl:stationIconUrl(netKey, fKey),iconSize:[24,24],iconAnchor:[12,12]});
            const m = L.marker([s.lat,s.lng],{icon})
                .bindTooltip(stationTooltipHtml(s),{direction:'top',offset:[0,-14],opacity:.95});
            m.on('click',(e)=>{L.DomEvent.stopPropagation(e);this.clickStation(s.id,m.getElement());});
            m._netKey = netKey;
            this._stationsMarkers[s.id] = m;
            if(!byNet[netKey]) byNet[netKey] = [];
            byNet[netKey].push(m);
        }

        for(const net of this.STATION_NETWORKS){
            const layer = this._ensureStationLayer(net.key);
            layer.clearLayers();
            if(byNet[net.key]?.length) layer.addLayers(byNet[net.key]);
        }
    },
    // ── Clic sur une station météo → volet droit (relevés + historique du jour) ──
    clickStation(id, markerEl){
        const s=this.weatherStations.find(x=>x.id===id);
        if(!s) return;
        this._clearAllMarkerSelection();
        this.site={};
        this._stationObj=s;
        this.selectedFeature={type:'station', ...s};
        this.chartData=null; this.multimodelData=null; this.multimodel5Data=null;
        this.baliseData=null; this._baliseObj=null;
        this.stationData=null; this.stationTableOpen=false;
        this.openRightPanel();
        this.loadStationDetail(id);
    },
    async loadStationDetail(id){
        this.stationLoading=true;
        try{
            const res=await fetch(`/api/weather-stations/${id}/detail`);
            this.stationData=await res.json();
        }catch(e){console.error('station detail failed',e);}
        this.stationLoading=false;
        this.$nextTick(()=>this.renderStationCharts());
    },
    renderStationCharts(){
        if(!this.stationData?.readings?.length) return;
        buildStationWindSVG(this.stationData);
        buildStationTempSVG(this.stationData);
        buildStationPressureSVG(this.stationData);
    },
    get stationNetworkLabel(){
        const net = this.selectedFeature?.network ?? this._stationObj?.network ?? '';
        const map = {mf:'Météo-France',metar:'METAR',infoclimat:'Infoclimat'};
        return map[net] ?? net.toUpperCase();
    },
    get stationFreshness(){
        return formatAge(this.stationData?.latest?.observed_at ?? this._stationObj?.reading?.observed_at);
    },
    get stationFreshnessClass(){
        const ts = this.stationData?.latest?.observed_at ?? this._stationObj?.reading?.observed_at;
        if(!ts) return 'dead';
        const age = (Date.now() - new Date(ts).getTime()) / 60000;
        if(age < 30) return 'fresh';
        if(age < 120) return 'stale';
        return 'dead';
    },
    get stationHasTemp(){
        return (this.stationData?.readings ?? []).some(r => r.temperature !== null);
    },
    get stationHasPressure(){
        return (this.stationData?.readings ?? []).some(r => r.pressure_hpa !== null);
    },

    toggleStationNetwork(key){
        if(!(key in this.stationNetworksVisible)) return;
        this.stationNetworksVisible[key] = !this.stationNetworksVisible[key];
        if(this.stationNetworksVisible[key]){
            if(!this.stationZoomOk()) return;
            if(!this.weatherStations.length){
                this.loadStations();
                return;
            }
            const layer = this._stationsLayers[key];
            if(layer) layer.addTo(this.map);
            else this.renderStations();
        } else {
            const layer = this._stationsLayers[key];
            if(layer && this.map) this.map.removeLayer(layer);
        }
    },
};}
