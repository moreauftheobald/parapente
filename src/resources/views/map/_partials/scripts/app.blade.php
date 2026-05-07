// ── Composant Alpine principal ───────────────────────────────
// Tout l'état et le comportement de la vue carte. Doit être inclus
// en dernier (utilise toutes les fonctions/constantes définies avant).
function mapApp(){return{
    map:null,tl:null,markers:{},
    sites:[],allScores:{},sunWindows:{},
    days:[],selectedDayIdx:0,
    site:{},
    currentBasemap:'topo',basemapList:BASEMAP_LIST,
    dayDropOpen:false,dayDropPos:{top:0,left:0},
    bmDropOpen:false,bmDropPos:{top:0,right:0},
    // Popup chart
    chartOpen:false,chartPos:{top:0,left:0},
    chartLoading:false,chartData:null,
    chartSite:null,chartSunWindow:null,
    _chartSiteObj:null, // référence site pour "Détails >"
    // Side panel (mode comparaison multi-modèles)
    panelOpen:false,panelTab:'today',panelDayIdx:0,
    multimodelData:null,multimodelLoading:false,
    multimodel5Data:null,multimodel5Loading:false,
    _panelMapState:null, // sauvegarde center+zoom carte avant ouverture
    // État collapse de chaque section graphe (séparé par onglet)
    chartCollapsed:{},chartCollapsed5:{},
    // Tooltip flottant des graphes
    tooltip:{visible:false, x:0, y:0, hour:'', consensus:null, rows:[]},

    // Balises météo (PiouPiou pour la phase 1.1)
    balises:[], balisesVisible:true,
    _balisesLayer:null,         // L.layerGroup
    _balisesMarkers:{},         // balise.id => L.marker
    _balisesTimer:null,         // setInterval handle

    // Configuration des graphes exposée pour le template
    CHART_CONFIGS,

    async init(){
        await this.$nextTick();
        this.initMap();
        await this.loadSites();
        // Charge les balises et rafraîchit toutes les 5 minutes
        await this.loadBalises();
        this._balisesTimer = setInterval(() => this.loadBalises(), 5 * 60 * 1000);
        // Re-rendu des graphes du panel sur redimensionnement
        window.addEventListener('resize', () => {
            if (!this.panelOpen) return;
            if (this.panelTab === 'today'    && this.multimodelData)  this.renderCharts();
            if (this.panelTab === 'fivedays' && this.multimodel5Data) this.renderCharts5();
        });
    },

    initMap(){
        this.map=L.map('map',{center:[49.1,5.5],zoom:7});
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
    get currentBasemapObj(){return BASEMAP_LIST.find(b=>b.key===this.currentBasemap)??BASEMAP_LIST[0];},

    toggleDayDrop(btn){if(!this.dayDropOpen){const r=btn.getBoundingClientRect();this.dayDropPos={top:r.bottom+6,left:r.left};}this.dayDropOpen=!this.dayDropOpen;this.bmDropOpen=false;},
    toggleBmDrop(btn){if(!this.bmDropOpen){const r=btn.getBoundingClientRect();this.bmDropPos={top:r.bottom+6,right:window.innerWidth-r.right};}this.bmDropOpen=!this.bmDropOpen;this.dayDropOpen=false;},

    async loadSites(){
        const r=await fetch('/api/sites');this.sites=await r.json();
        await Promise.all(this.sites.map(s=>this.loadSiteScores(s.id)));
        this.buildDays();this.renderMarkers();
    },
    async loadSiteScores(id){
        try{const r=await fetch(`/api/sites/${id}/scores`);const d=await r.json();this.allScores[id]=d.scores||[];this.sunWindows[id]=d.sun_windows||{};}
        catch(e){this.allScores[id]=[];}
    },

    buildDays(){
        const m={};
        Object.values(this.allScores).flat().forEach(s=>{if(!m[s.day])m[s.day]=[];m[s.day].push(s);});
        this.days=Object.keys(m).slice(0,5).map(day=>{
            const sl=m[day];
            const gs=[...new Set(Object.values(this.allScores).flat().filter(s=>s.day===day&&s.status==='green').map(s=>s.hour))].length;
            const[d,mo]=day.split('/');
            const dt=new Date(new Date().getFullYear(),parseInt(mo)-1,parseInt(d));
            const now=new Date(),tom=new Date(now);tom.setDate(now.getDate()+1);
            const label=dt.toDateString()===now.toDateString()?'Aujourd\'hui':dt.toDateString()===tom.toDateString()?'Demain':DF[dt.getDay()]+' '+d+'/'+mo;
            return{label,raw:day,bestStatus:sl.some(s=>s.status==='green')?'green':sl.some(s=>s.status==='orange')?'orange':'red',greenSlots:gs};
        });
    },
    selectDay(idx){this.selectedDayIdx=idx;this.renderMarkers();if(this.chartOpen&&this.chartData)this.$nextTick(()=>this.refreshChart());},

    get greenCount(){const day=this.days[this.selectedDayIdx]?.raw;if(!day)return 0;return this.sites.filter(s=>(this.allScores[s.id]||[]).some(sc=>sc.day===day&&sc.status==='green')).length;},

    renderMarkers(){
        const day=this.days[this.selectedDayIdx]?.raw;
        this.sites.forEach(site=>{
            const scores=(this.allScores[site.id]||[]).filter(s=>s.day===day);
            let st='unknown';
            if(scores.some(s=>s.status==='green'))st='green';
            else if(scores.some(s=>s.status==='orange'))st='orange';
            else if(scores.length>0)st='red';
            const icon=L.divIcon({className:'',html:siteIconHtml(site,st),iconSize:[40,40],iconAnchor:[20,20]});
            if(this.markers[site.id]){
                const was=this.markers[site.id].getElement()?.querySelector('.pg-site-marker')?.classList.contains('selected');
                this.markers[site.id].setIcon(icon);
                if(was)setTimeout(()=>this.markers[site.id]?.getElement()?.querySelector('.pg-site-marker')?.classList.add('selected'),10);
            }else{
                const mk=L.marker([site.lat,site.lng],{icon}).addTo(this.map).bindTooltip(site.name,{permanent:false,direction:'top',offset:[0,-22]});
                mk.on('click',(e)=>{L.DomEvent.stopPropagation(e);this.clickSite(site,mk.getElement());});
                this.markers[site.id]=mk;
            }
        });
    },

    // Clic sur un marker → ouvre le popup chart
    async clickSite(site, markerEl){
        this.chartOpen=false;
        this._chartSiteObj=site;

        // Positionner le popup
        const r=markerEl?.getBoundingClientRect()??{top:200,left:200,right:220,bottom:240};
        const pw=600, ph=400;
        let left=r.right+12;
        if(left+pw>window.innerWidth-10) left=r.left-pw-12;
        if(left<10) left=10;
        let top=r.top-100;
        if(top<60) top=60;
        if(top+ph>window.innerHeight-10) top=window.innerHeight-ph-10;
        this.chartPos={top,left};

        // Sélectionner le marqueur visuellement
        Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-site-marker')?.classList.remove('selected'));
        markerEl?.querySelector('.pg-site-marker')?.classList.add('selected');

        this.chartSite=null;this.chartSunWindow=null;this.chartData=null;
        this.chartLoading=true;this.chartOpen=true;

        try{
            const res=await fetch(`/api/sites/${site.id}/chart`);
            this.chartData=await res.json();
            this.chartSite=this.chartData.site;
            this.refreshChart();
        }catch(e){console.error(e);}
        this.chartLoading=false;
    },

    refreshChart(){
        if(!this.chartData) return;
        const day=this.days[this.selectedDayIdx]?.raw;
        const dayData=this.chartData.days?.[day]??[];
        this.chartSunWindow=this.chartData.sun_windows?.[day]??null;
        this.$nextTick(()=>buildChartSVG(dayData,this.chartData.site));
    },

    // Bouton "Détails ›" dans le popup → ouvre le panel multi-modèles
    openPanel(){
        if(!this._chartSiteObj) return;
        const s=this._chartSiteObj;
        this.site=s;
        this.panelTab='today';
        this.panelDayIdx=this.selectedDayIdx;
        this.panelOpen=true;
        document.getElementById('panel').classList.add('open');
        this._recenterMapForPanel(s);
        this.loadMultimodel();
    },
    closePanel(){
        document.getElementById('panel').classList.remove('open');
        this.panelOpen=false;
        this.multimodelData=null;
        this.multimodel5Data=null;
        this._restoreMapState();
        Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-site-marker')?.classList.remove('selected'));
        this.site={};
    },

    // ── Side panel : navigation jour + chargement multi-modèles ──
    get panelDay(){return this.days[this.panelDayIdx]??null;},
    get conformityColor(){
        return this._conformityColor(this.multimodelData?.conformity_pct);
    },
    get conformity5Color(){
        return this._conformityColor(this.multimodel5Data?.conformity_pct);
    },
    get fiveDaysRangeLabel(){
        const slice = this.days.slice(0, 5);
        if (!slice.length) return '—';
        return slice[0].raw + ' → ' + slice[slice.length-1].raw;
    },
    _conformityColor(c){
        if (c == null) return '#6b7280';
        if (c >= 75) return '#22c55e';
        if (c >= 50) return '#f59e0b';
        return '#ef4444';
    },
    panelDayShift(delta){
        const next=Math.max(0, Math.min(this.days.length-1, this.panelDayIdx+delta));
        if(next===this.panelDayIdx) return;
        this.panelDayIdx=next;
        this.loadMultimodel();
    },
    // Bascule d'onglet : déclenche le chargement de la vue 5 jours à
    // la première activation, puis re-render après changement.
    async setPanelTab(tab){
        this.panelTab = tab;
        if (tab === 'fivedays') {
            if (!this.multimodel5Data && !this.multimodel5Loading) {
                await this.loadFiveDays();
            } else if (this.multimodel5Data) {
                this.$nextTick(() => this.renderCharts5());
            }
        } else if (tab === 'today' && this.multimodelData) {
            this.$nextTick(() => this.renderCharts());
        }
    },
    async loadMultimodel(){
        if(!this.site?.id) return;
        const day=this.days[this.panelDayIdx]?.raw;
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
    // unique avec hours = [0..119] (5 jours × 24h). Stocke aussi les
    // séparateurs et labels pour l'affichage de l'axe X.
    async loadFiveDays(){
        if(!this.site?.id || !this.days.length) return;
        this.multimodel5Loading = true;
        this.multimodel5Data    = null;
        try {
            const slice  = this.days.slice(0, 5);
            const ymds   = slice.map(d => this._dayRawToYmd(d.raw));
            const reqs   = ymds.map(ymd =>
                fetch(`/api/sites/${this.site.id}/multimodel?day=${ymd}&period=24h`).then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
            );
            const responses = await Promise.all(reqs);

            const merged = {
                viewMode:       'fivedays',
                site:           responses[0].site,
                models:         responses[0].models,
                hours:          [],
                data:           {},
                consensus:      {},
                day_separators: [],
                day_labels:     [],
                sun_windows_by_day: [],
            };
            responses.forEach((resp, dayIdx) => {
                const offset = dayIdx * 24;
                for (let h = 0; h < 24; h++) {
                    const flat = offset + h;
                    merged.hours.push(flat);
                    if (resp.data && resp.data[h])      merged.data[flat]      = resp.data[h];
                    if (resp.consensus && resp.consensus[h]) merged.consensus[flat] = resp.consensus[h];
                }
                if (dayIdx < responses.length - 1) merged.day_separators.push((dayIdx + 1) * 24);
                merged.day_labels.push({offset, label: slice[dayIdx]?.label || slice[dayIdx]?.raw || '', raw: slice[dayIdx]?.raw});
                merged.sun_windows_by_day.push({
                    offset,
                    start: resp.sun_window?.start_hour ?? null,
                    end:   resp.sun_window?.end_hour   ?? null,
                });
            });
            const conformities = responses.map(r => r.conformity_pct).filter(c => c != null);
            merged.conformity_pct = conformities.length
                ? Math.round(conformities.reduce((a, b) => a + b, 0) / conformities.length)
                : null;
            this.multimodel5Data = merged;
        } catch (e) {
            console.error('5-day load failed', e);
        }
        this.multimodel5Loading = false;
        this.$nextTick(() => this.renderCharts5());
    },
    renderCharts(){
        if(!this.multimodelData) return;
        CHART_CONFIGS.forEach(cfg => {
            if (this.chartCollapsed[cfg.id]) return;
            const fn = cfg.type === 'bar' ? buildBarChart : buildLineChart;
            fn('svg-' + cfg.id, cfg, this.multimodelData, this);
        });
    },
    renderCharts5(){
        if(!this.multimodel5Data) return;
        CHART_CONFIGS.forEach(cfg => {
            if (this.chartCollapsed5[cfg.id]) return;
            const fn = cfg.type === 'bar' ? buildBarChart : buildLineChart;
            fn('svg5-' + cfg.id, cfg, this.multimodel5Data, this);
        });
    },
    toggleChart(id){
        this.chartCollapsed[id] = !this.chartCollapsed[id];
        if (!this.chartCollapsed[id]) {
            this.$nextTick(() => {
                const cfg = CHART_CONFIGS.find(c => c.id === id);
                if (!cfg || !this.multimodelData) return;
                const fn = cfg.type === 'bar' ? buildBarChart : buildLineChart;
                fn('svg-' + id, cfg, this.multimodelData, this);
            });
        }
    },
    toggleChart5(id){
        this.chartCollapsed5[id] = !this.chartCollapsed5[id];
        if (!this.chartCollapsed5[id]) {
            this.$nextTick(() => {
                const cfg = CHART_CONFIGS.find(c => c.id === id);
                if (!cfg || !this.multimodel5Data) return;
                const fn = cfg.type === 'bar' ? buildBarChart : buildLineChart;
                fn('svg5-' + id, cfg, this.multimodel5Data, this);
            });
        }
    },
    _dayRawToYmd(raw){
        const [d,m]=raw.split('/');
        const yyyy=new Date().getFullYear();
        return `${yyyy}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    },

    // ── Recentrage carte à l'ouverture/fermeture du panel ──
    // Le panel est en position:absolute par-dessus la carte : le
    // conteneur ne change pas de taille (pas d'invalidateSize).
    // On décale juste le centre géographique de panelW/2 vers la
    // droite pour que le site apparaisse au milieu de la zone
    // visible (à gauche du panel). Le zoom courant est conservé.
    _recenterMapForPanel(site){
        if(!this.map) return;
        if(!this._panelMapState){
            this._panelMapState={center:this.map.getCenter(),zoom:this.map.getZoom()};
        }
        const panelW=document.getElementById('panel')?.offsetWidth??0;
        if(panelW===0) return;
        const sitePoint=this.map.latLngToContainerPoint([site.lat,site.lng]);
        const newCenterPoint=L.point(sitePoint.x + panelW/2, sitePoint.y);
        const newCenter=this.map.containerPointToLatLng(newCenterPoint);
        this.map.flyTo(newCenter, this.map.getZoom(), {duration:.6});
    },
    _restoreMapState(){
        if(!this.map || !this._panelMapState) return;
        const s=this._panelMapState; this._panelMapState=null;
        this.map.flyTo(s.center, s.zoom, {duration:.5});
    },

    // ── Balises météo ────────────────────────────────────────────
    async loadBalises(){
        try {
            const r = await fetch('/api/balises');
            if (!r.ok) throw new Error('HTTP '+r.status);
            this.balises = await r.json();
            this.renderBalises();
        } catch (e) {
            console.warn('loadBalises failed', e);
        }
    },
    renderBalises(){
        if (!this.map) return;
        // Layer group créé une fois, ajouté/retiré selon visibilité
        if (!this._balisesLayer) {
            this._balisesLayer = L.layerGroup();
            if (this.balisesVisible) this._balisesLayer.addTo(this.map);
        }
        const seen = new Set();
        for (const b of this.balises) {
            seen.add(b.id);
            const iconUrl = baliseIconUrl(b.reading);
            const icon    = L.icon({iconUrl, iconSize:[40,40], iconAnchor:[20,20]});
            const tooltip = baliseTooltipHtml(b);
            const existing = this._balisesMarkers[b.id];
            if (existing) {
                existing.setIcon(icon);
                existing.setLatLng([b.lat, b.lng]);
                existing.setTooltipContent(tooltip);
            } else {
                const m = L.marker([b.lat, b.lng], {icon})
                    .bindTooltip(tooltip, {direction:'top', offset:[0,-22], opacity:.95});
                m.addTo(this._balisesLayer);
                this._balisesMarkers[b.id] = m;
            }
        }
        // Nettoie les marqueurs obsolètes (balise désactivée entre 2 polls)
        for (const id of Object.keys(this._balisesMarkers)) {
            if (!seen.has(parseInt(id, 10))) {
                this._balisesLayer.removeLayer(this._balisesMarkers[id]);
                delete this._balisesMarkers[id];
            }
        }
    },
    toggleBalises(){
        this.balisesVisible = !this.balisesVisible;
        if (!this._balisesLayer || !this.map) return;
        if (this.balisesVisible) {
            this._balisesLayer.addTo(this.map);
        } else {
            this.map.removeLayer(this._balisesLayer);
        }
    },
};}
