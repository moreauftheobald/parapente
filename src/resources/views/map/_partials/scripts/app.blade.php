// ── Composant Alpine principal ───────────────────────────────
// Tout l'état et le comportement de la vue carte. Doit être inclus
// en dernier (utilise toutes les fonctions/constantes définies avant).
function mapApp(){return{
    map:null,tl:null,markers:{},
    sites:[],allScores:{},sunWindows:{},dayQuality:{},
    // Scoring perso : 'user' | 'global' par site_id (renvoyé par /api/sites/{id}/scores).
    // Vide pour les invités, ou pour les sites sans scoring perso actif.
    scoringSource:{},
    days:[],selectedDayIdx:0,
    site:{},
    currentBasemap:'topo',basemapList:BASEMAP_LIST,
    dayDropOpen:false,dayDropPos:{top:0,left:0},
    bmDropOpen:false,bmDropPos:{top:0,left:0,width:0},
    // Volet gauche (onglets Paramètres / Légende, repliable) ; volet droit (détail)
    leftCollapsed:false, lpTab:'params', rightPanelOpen:false, selectedFeature:null,
    // Filtres d'affichage des sites par statut météo
    showGreen:true, showOrange:true, showRed:true,
    // Filtre « Mes sites » : ne montrer que les sites avec scoring perso (actif ou inactif).
    // Disponible uniquement quand l'utilisateur est connecté.
    onlyMyScorings:false,
    authUser: AUTH_USER,

    // ── Volet droit (site) : onglets + données ──
    rpTab:'synthese',                              // synthese | voting | models | models5
    chartData:null, chartLoading:false, chartSite:null,   // onglet « Synthèse » (= ancienne popup)
    multimodelData:null,  multimodelLoading:false,        // onglet « Modèles du jour »
    multimodel5Data:null, multimodel5Loading:false,       // onglet « Modèles 5 jours »
    chartCollapsed:{}, chartCollapsed5:{},                // état replié de chaque section graphe
    tooltip:{visible:false, x:0, y:0, hour:'', consensus:null, rows:[]},  // tooltip flottant des graphes
    // Tooltip dédié aux cellules de l'onglet « Détail scoring » — utile
    // notamment pour les cellules split (2 valeurs à afficher).
    votingTip:{visible:false, x:0, y:0, lines:[]},

    // Balises météo — un layerGroup et un toggle par réseau
    // (les clés DOIVENT correspondre à `balises.source` côté API
    //  : pioupiou / metar / windy)
    BALISE_NETWORKS: [
        { key:'pioupiou', label:'PiouPiou', icon:'🪁' },
        { key:'metar',    label:'METAR',    icon:'✈️' },
        { key:'windy',    label:'Windy',    icon:'🌬️' },
    ],
    balises:[],
    networksVisible:{ pioupiou:true, metar:true, windy:true },
    _balisesLayers:{},        // { source: L.layerGroup() }
    _balisesMarkers:{},       // { baliseId: L.marker } (toutes sources confondues)
    _balisesTimer:null,
    // Volet droit (balise) : relevés + historique du jour
    baliseData:null, baliseLoading:false, _baliseObj:null,

    // Configuration des graphes exposée pour le template
    CHART_CONFIGS,

    async init(){
        await this.$nextTick();
        this.initMap();
        await this.loadSites();
        await this.loadBalises();
        this._balisesTimer = setInterval(() => this.loadBalises(), 5 * 60 * 1000);
        // Re-rendu des graphes du volet droit sur redimensionnement
        window.addEventListener('resize', () => {
            if (!this.rightPanelOpen) return;
            if (this.selectedFeature?.type === 'site') {
                if (this.rpTab === 'synthese' && this.chartData)       this.renderSynthese();
                if (this.rpTab === 'models'   && this.multimodelData)  this.renderCharts();
                if (this.rpTab === 'models5'  && this.multimodel5Data) this.renderCharts5();
            } else if (this.selectedFeature?.type === 'balise' && this.baliseData) {
                this.renderBaliseCharts();
            }
        });
    },

    initMap(){
        this.map=L.map('map',{center:[49.1,5.5],zoom:7,zoomControl:false});
        L.control.zoom({position:'topright'}).addTo(this.map);
        const b=BASEMAP_LIST[0];
        this.tl=L.tileLayer(b.url,{attribution:b.attribution,maxZoom:b.maxZoom}).addTo(this.map);
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

    // ── Volet gauche : repli en barre étroite ────────────────────
    toggleLeftPanel(){
        this.leftCollapsed=!this.leftCollapsed;
        setTimeout(()=>this.map?.invalidateSize(),320);
    },

    // ── Volet droit : ouverture / fermeture ──────────────────────
    openRightPanel(){
        this.rightPanelOpen=true;
        // Largeur animée (.35s) : on revalide la taille de la carte et on
        // recentre sur le marqueur une fois la transition finie.
        setTimeout(()=>{
            this.map?.invalidateSize();
            if(this.selectedFeature?.lat!=null) this.map?.panTo([this.selectedFeature.lat,this.selectedFeature.lng]);
        },380);
    },
    closeRightPanel(){
        this.rightPanelOpen=false;
        this.selectedFeature=null;
        this.chartData=null; this.chartSite=null;
        this.multimodelData=null; this.multimodel5Data=null;
        this.baliseData=null; this._baliseObj=null;
        Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-site-marker')?.classList.remove('selected'));
        setTimeout(()=>this.map?.invalidateSize(),380);
    },

    async loadSites(){
        const r=await fetch('/api/sites',{credentials:'same-origin'});
        this.sites=await r.json();
        await Promise.all(this.sites.map(s=>this.loadSiteScores(s.id)));
        this.buildDays();this.renderMarkers();
    },
    async loadSiteScores(id){
        try{
            const r=await fetch(`/api/sites/${id}/scores`,{credentials:'same-origin'});
            const d=await r.json();
            this.allScores[id]=d.scores||[];
            this.sunWindows[id]=d.sun_windows||{};
            this.dayQuality[id]=d.day_quality||{};
            // 'user' (scoring perso actif) ou 'global' (scoring standard).
            this.scoringSource[id]=d.scoring_source||'global';
        }
        catch(e){this.allScores[id]=[];this.dayQuality[id]={};this.scoringSource[id]='global';}
    },

    /** Retourne l'état du scoring perso de l'utilisateur sur un site :
     *  'active' | 'inactive' | null. Utilisé pour les badges du marker
     *  et le filtre « Mes sites ». Donnée fournie par /api/sites. */
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

    buildDays(){
        const m={};
        Object.values(this.allScores).flat().forEach(s=>{if(!m[s.day])m[s.day]=[];m[s.day].push(s);});
        const rank={green:3,orange:2,red:1,unknown:0};
        this.days=Object.keys(m).slice(0,5).map(day=>{
            // meilleur statut "jour" parmi tous les sites (viabilité, pas heure-par-heure)
            let best='unknown';
            for(const site of this.sites){
                const st=this.dayQuality[site.id]?.[day]?.status;
                if(st && (rank[st]??0) > (rank[best]??0)) best=st;
            }
            const gs=[...new Set(Object.values(this.allScores).flat().filter(s=>s.day===day&&s.status==='green').map(s=>s.hour))].length;
            const[d,mo]=day.split('/');
            const dt=new Date(new Date().getFullYear(),parseInt(mo)-1,parseInt(d));
            const now=new Date(),tom=new Date(now);tom.setDate(now.getDate()+1);
            const label=dt.toDateString()===now.toDateString()?'Aujourd\'hui':dt.toDateString()===tom.toDateString()?'Demain':DF[dt.getDay()]+' '+d+'/'+mo;
            return{label,raw:day,bestStatus:best,greenSlots:gs};
        });
    },
    selectDay(idx){
        this.selectedDayIdx=idx;
        this.renderMarkers();
        // Si le volet droit affiche un site : le jour a changé → MAJ contenu
        if(this.rightPanelOpen && this.selectedFeature?.type==='site'){
            this.multimodelData=null; // endpoint multimodèle est par jour → invalidé
            if(this.rpTab==='synthese' && this.chartData) this.$nextTick(()=>this.renderSynthese());
            if(this.rpTab==='models') this.loadMultimodel();
        }
    },

    _statusVisible(st){
        if(st==='green') return this.showGreen;
        if(st==='orange') return this.showOrange;
        if(st==='red') return this.showRed;
        return true; // statut inconnu : toujours affiché
    },
    _siteVisible(site, status){
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
        const day=this.days[this.selectedDayIdx]?.raw;
        this.sites.forEach(site=>{
            // Statut du jour = qualité de la journée (viabilité : continuité + créneaux midi)
            const st=this.dayQuality[site.id]?.[day]?.status ?? 'unknown';

            // Filtres : statut météo + « Mes sites »
            if(! this._siteVisible(site, st)){
                if(this.markers[site.id]){this.map.removeLayer(this.markers[site.id]);delete this.markers[site.id];}
                return;
            }

            const icon=L.divIcon({className:'',html:siteIconHtml(site,st),iconSize:[40,40],iconAnchor:[20,20]});
            if(this.markers[site.id]){
                const was=this.markers[site.id].getElement()?.querySelector('.pg-site-marker')?.classList.contains('selected');
                this.markers[site.id].setIcon(icon);
                if(was)setTimeout(()=>this.markers[site.id]?.getElement()?.querySelector('.pg-site-marker')?.classList.add('selected'),10);
            }else{
                const mk=L.marker([site.lat,site.lng],{icon}).addTo(this.map).bindTooltip(site.name,{permanent:false,direction:'top',offset:[0,-22]});
                mk.on('click',(e)=>{L.DomEvent.stopPropagation(e);this.clickSite(site,mk.getElement());});
                this.markers[site.id]=mk;
                if(this.selectedFeature?.type==='site'&&this.selectedFeature.id===site.id)
                    setTimeout(()=>mk.getElement()?.querySelector('.pg-site-marker')?.classList.add('selected'),10);
            }
        });
    },

    // ── Clic sur un site → volet droit (onglet Synthèse par défaut) ──
    clickSite(site, markerEl){
        Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-site-marker')?.classList.remove('selected'));
        markerEl?.querySelector('.pg-site-marker')?.classList.add('selected');
        this.site=site;
        this.selectedFeature={type:'site',...site};
        this.rpTab='synthese';
        this.chartData=null; this.chartSite=null;
        this.multimodelData=null; this.multimodel5Data=null;
        this.openRightPanel();
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
    renderSynthese(){
        if(!this.chartData) return;
        const day=this.days[this.selectedDayIdx]?.raw;
        const dayData=this.chartData.days?.[day]??[];
        this.$nextTick(()=>{
            buildChartSVG(dayData,this.chartData.site);
            buildCeilingSVG(dayData,this.chartData.site);
        });
    },
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
    async setRpTab(tab){
        this.rpTab=tab;
        if(tab==='synthese'){
            if(this.chartData) this.$nextTick(()=>this.renderSynthese());
        }else if(tab==='voting'){
            // L'onglet « Détail du scoring » est purement déclaratif :
            // il lit allScores[site.id] (déjà chargé via /api/sites/{id}/scores)
            // qui contient désormais le sous-champ `detail` avec les couleurs.
        }else if(tab==='models'){
            if(!this.multimodelData && !this.multimodelLoading) await this.loadMultimodel();
            else if(this.multimodelData) this.$nextTick(()=>this.renderCharts());
        }else if(tab==='models5'){
            if(!this.multimodel5Data && !this.multimodel5Loading) await this.loadFiveDays();
            else if(this.multimodel5Data) this.$nextTick(()=>this.renderCharts5());
        }
    },

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
        this.multimodelData=null;
        this.multimodelLoading=true;
        try{
            const r=await fetch(`/api/sites/${this.site.id}/multimodel?day=${ymd}&period=24h`);
            if(!r.ok) throw new Error('HTTP '+r.status);
            this.multimodelData=await r.json();
        }catch(e){console.error('multimodel load failed',e);}
        this.multimodelLoading=false;
        this.$nextTick(()=>this.renderCharts());
    },
    // Charge les 5 jours en parallèle et fusionne en une structure
    // unique avec hours = [0..119] (5 jours × 24h).
    async loadFiveDays(){
        if(!this.site?.id || !this.days.length) return;
        this.multimodel5Loading=true;
        this.multimodel5Data=null;
        try{
            const slice=this.days.slice(0,5);
            const ymds=slice.map(d=>this._dayRawToYmd(d.raw));
            const reqs=ymds.map(ymd=>
                fetch(`/api/sites/${this.site.id}/multimodel?day=${ymd}&period=24h`).then(r=>{
                    if(!r.ok) throw new Error('HTTP '+r.status);
                    return r.json();
                })
            );
            const responses=await Promise.all(reqs);
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
        try{
            const r=await fetch('/api/balises');
            if(!r.ok) throw new Error('HTTP '+r.status);
            this.balises=await r.json();
            this.renderBalises();
        }catch(e){console.warn('loadBalises failed',e);}
    },
    /** Source d'une balise, normalisée vers une clé connue de
     *  BALISE_NETWORKS. Un fournisseur inattendu (ex. holfuy à venir)
     *  est rangé dans pioupiou par défaut pour rester visible. */
    _baliseNetworkKey(b){
        const known = this.BALISE_NETWORKS.map(n => n.key);
        return known.includes(b.source) ? b.source : 'pioupiou';
    },
    /** layerGroup d'un réseau, créé à la volée si nécessaire. */
    _ensureNetworkLayer(key){
        if(!this._balisesLayers[key]){
            this._balisesLayers[key] = L.layerGroup();
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
        const seen = new Set();
        for(const b of this.balises){
            seen.add(b.id);
            const netKey  = this._baliseNetworkKey(b);
            const layer   = this._ensureNetworkLayer(netKey);
            const icon    = L.icon({iconUrl:baliseIconUrl(b.reading), iconSize:[40,40], iconAnchor:[20,20]});
            const tooltip = baliseTooltipHtml(b);
            const existing = this._balisesMarkers[b.id];
            if(existing){
                existing.setIcon(icon);
                existing.setLatLng([b.lat,b.lng]);
                existing.setTooltipContent(tooltip);
                // Si la source d'une balise change (rare mais possible
                // si on l'a rebadgée côté admin), on la déplace de layer
                if(existing._netKey !== netKey){
                    if(this._balisesLayers[existing._netKey]){
                        this._balisesLayers[existing._netKey].removeLayer(existing);
                    }
                    layer.addLayer(existing);
                    existing._netKey = netKey;
                }
            }else{
                const m = L.marker([b.lat,b.lng],{icon})
                    .bindTooltip(tooltip,{direction:'top',offset:[0,-22],opacity:.95});
                m._netKey = netKey;
                m.on('click',(e)=>{L.DomEvent.stopPropagation(e);this.clickBalise(b.id,m.getElement());});
                m.addTo(layer);
                this._balisesMarkers[b.id] = m;
            }
        }
        for(const id of Object.keys(this._balisesMarkers)){
            if(!seen.has(parseInt(id,10))){
                const m = this._balisesMarkers[id];
                if(m._netKey && this._balisesLayers[m._netKey]){
                    this._balisesLayers[m._netKey].removeLayer(m);
                }
                delete this._balisesMarkers[id];
            }
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
    clickBalise(id, markerEl){
        const b=this.balises.find(x=>x.id===id);
        if(!b) return;
        Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-site-marker')?.classList.remove('selected'));
        this.site={};
        this._baliseObj=b;
        this.selectedFeature={type:'balise', ...b};
        this.chartData=null; this.multimodelData=null; this.multimodel5Data=null;
        this.baliseData=null;
        this.openRightPanel();
        this.loadBaliseHistory(id);
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
    baliseFreshness(){
        const iso=this.baliseData?.latest?.read_at ?? this._baliseObj?.reading?.read_at;
        if(!iso) return 'aucune lecture';
        const ageMin=Math.round((Date.now()-new Date(iso).getTime())/60000);
        if(ageMin<1)  return "à l'instant";
        if(ageMin<60) return `il y a ${ageMin} min`;
        return `il y a ${Math.round(ageMin/60)} h`;
    },
    baliseTrendArrow(){
        const t=this._baliseObj?.reading?.trend ?? 0;
        return ['↓↓','↓','→','↑','↑↑'][t+2] ?? '→';
    },
    baliseTrendColor(){
        const t=this._baliseObj?.reading?.trend ?? 0;
        if(t>=1)  return '#fb923c';
        if(t<=-1) return '#60a5fa';
        return '#9ca3af';
    },
    get baliseReseauLabel(){
        return (this.baliseData?.balise?.source ?? this.selectedFeature?.source ?? '').toUpperCase();
    },
};}
