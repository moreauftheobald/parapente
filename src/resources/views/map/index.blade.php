@extends('layouts.app')
@section('title', 'Carte météo parapente — Grand Est')

@push('styles')
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; }
        .mono { font-family:'DM Mono',monospace; }

        #pg-app  { display:flex; flex-direction:column; height:100%; overflow:hidden; }
        #pg-toolbar { flex-shrink:0; height:56px; background:#111827; border-bottom:1px solid rgba(55,65,81,.6); display:flex; align-items:center; padding:0 16px; gap:12px; }
        #map-wrap { flex:1 1 0%; min-height:0; overflow:hidden; position:relative; }
        #map { height:100%; width:100%; }

        .dd-trigger { display:flex; align-items:center; gap:10px; padding:8px 14px; border-radius:12px; cursor:pointer; background:#1f2937; border:1px solid rgba(75,85,99,.5); color:#e5e7eb; font-size:14px; transition:border-color .15s; white-space:nowrap; }
        .dd-trigger:hover { border-color:rgba(156,163,175,.6); }
        .dd-arrow { font-size:10px; color:#6b7280; display:inline-block; transition:transform .2s; }
        .dd-arrow.open { transform:rotate(180deg); }

        /* Dropdowns en position:fixed — hors de tout stacking context */
        .dd-menu { position:fixed; background:#111827; border:1px solid rgba(55,65,81,.7); border-radius:16px; box-shadow:0 24px 48px rgba(0,0,0,.85); padding:6px 0; z-index:99999; }
        .dd-item { display:flex; align-items:center; gap:12px; padding:10px 16px; cursor:pointer; transition:background .1s; font-size:13px; color:#9ca3af; width:100%; background:none; border:none; text-align:left; }
        .dd-item:hover { background:rgba(255,255,255,.06); color:#e5e7eb; }
        .dd-item.is-active { background:rgba(255,255,255,.1); color:#fff; }
        .dd-sub { font-size:11px; color:#4b5563; }

        .pg-marker { cursor:pointer; transition:transform .15s,filter .15s; filter:drop-shadow(0 3px 6px rgba(0,0,0,.45)); display:block; }
        .pg-marker:hover { transform:scale(1.15); filter:drop-shadow(0 4px 10px rgba(0,0,0,.6)); }
        .pg-marker.selected { transform:scale(1.2); filter:drop-shadow(0 0 6px rgba(255,255,255,.7)) drop-shadow(0 4px 10px rgba(0,0,0,.6)); }

        #panel { transition:transform .35s cubic-bezier(.4,0,.2,1); transform:translateX(100%); }
        #panel.open { transform:translateX(0); }

        .slot-block { flex:1; height:20px; min-width:2px; cursor:pointer; border-radius:2px; transition:opacity .1s,transform .1s; }
        .slot-block:hover { opacity:.7; transform:scaleY(1.25); }
        .slot-block.sel { outline:2px solid white; outline-offset:1px; z-index:1; }

        @keyframes spin { to { transform:rotate(360deg); } }

        ::-webkit-scrollbar { width:3px; }
        ::-webkit-scrollbar-thumb { background:#2d3748; border-radius:2px; }

        .leaflet-tooltip { background:#0f172a !important; border:1px solid #1e293b !important; color:#cbd5e1 !important; font-family:'DM Sans',sans-serif !important; font-size:12px !important; padding:5px 10px !important; border-radius:8px !important; box-shadow:0 4px 16px rgba(0,0,0,.5) !important; }
        .leaflet-tooltip-top:before { border-top-color:#1e293b !important; }
        .leaflet-control-zoom a { background:#1e293b !important; color:#64748b !important; border-color:#334155 !important; }
        .leaflet-control-zoom a:hover { background:#334155 !important; color:white !important; }
        .leaflet-bar { border-color:#334155 !important; box-shadow:0 2px 8px rgba(0,0,0,.4) !important; }
    </style>
@endpush

@section('content')
    {{-- Tout dans un seul x-data — pas de x-data imbriqués, pas de x-teleport --}}
    <div id="pg-app" x-data="mapApp()" x-init="init()"
         @click.window="dayDropOpen=false; bmDropOpen=false;">

        {{-- ═══ TOOLBAR ════════════════════════════════════════════ --}}
        <div id="pg-toolbar">

            {{-- Sélecteur de journée --}}
            <button class="dd-trigger" style="min-width:230px;"
                    @click.stop="toggleDayDrop($el)">
            <span style="width:10px;height:10px;border-radius:50%;flex-shrink:0;"
                  :style="{background:days[selectedDayIdx]?.bestStatus==='green'?'#22c55e':days[selectedDayIdx]?.bestStatus==='orange'?'#f59e0b':days[selectedDayIdx]?.bestStatus==='red'?'#ef4444':'#6b7280'}"></span>
                <div style="flex:1;text-align:left;">
                    <div style="font-weight:500;color:#fff;font-size:14px;" x-text="days[selectedDayIdx]?.label??'Chargement…'"></div>
                    <div style="font-size:11px;margin-top:1px;">
                        <span x-show="days[selectedDayIdx]?.greenSlots>0" style="color:#4ade80;" x-text="days[selectedDayIdx]?.greenSlots+'h de vol possible'"></span>
                        <span x-show="!days[selectedDayIdx]?.greenSlots" style="color:#4b5563;">Aucun créneau favorable</span>
                    </div>
                </div>
                <span class="dd-arrow" :class="dayDropOpen?'open':''">▼</span>
            </button>

            {{-- Compteur --}}
            <div style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:50%;background:#22c55e;"></span>
                <span style="color:#4ade80;font-weight:500;" x-text="greenCount"></span>
                <span>/ <span x-text="sites.length"></span> volables</span>
            </div>

            <div style="flex:1;"></div>
            <div style="width:1px;height:24px;background:rgba(75,85,99,.4);"></div>

            {{-- Sélecteur fond de carte --}}
            <button class="dd-trigger" style="min-width:190px;"
                    @click.stop="toggleBmDrop($el)">
                <span x-text="currentBasemapObj.icon" style="font-size:16px;line-height:1;flex-shrink:0;"></span>
                <span style="flex:1;text-align:left;" x-text="currentBasemapObj.label"></span>
                <span class="dd-arrow" :class="bmDropOpen?'open':''">▼</span>
            </button>

        </div>

        {{-- ═══ CARTE ══════════════════════════════════════════════ --}}
        <div id="map-wrap">
            <div id="map"></div>

            {{-- Légende --}}
            <div style="position:absolute;bottom:20px;left:12px;z-index:1000;background:rgba(17,24,39,.92);border:1px solid rgba(55,65,81,.4);border-radius:12px;padding:12px;font-size:12px;backdrop-filter:blur(8px);box-shadow:0 4px 16px rgba(0,0,0,.4);">
                <div style="color:#4b5563;text-transform:uppercase;letter-spacing:.08em;font-size:9px;font-weight:500;margin-bottom:10px;">Légende</div>
                <div style="display:flex;flex-direction:column;gap:7px;">
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#22c55e;"></span><span style="color:#9ca3af;">Favorable</span></div>
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;"></span><span style="color:#9ca3af;">Incertain</span></div>
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#ef4444;"></span><span style="color:#9ca3af;">Défavorable</span></div>
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#6b7280;"></span><span style="color:#9ca3af;">Sans données</span></div>
                </div>
                <div style="margin-top:10px;padding-top:8px;border-top:1px solid rgba(55,65,81,.4);color:#374151;font-size:9px;">Lever−30min → Coucher+30min</div>
            </div>

            {{-- Panel site --}}
            <div id="panel" style="position:absolute;top:0;right:0;height:100%;width:384px;background:#111827;border-left:1px solid rgba(55,65,81,.5);z-index:500;display:flex;flex-direction:column;box-shadow:-8px 0 32px rgba(0,0,0,.5);">

                <div style="padding:20px 20px 16px;border-bottom:1px solid rgba(55,65,81,.4);flex-shrink:0;">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;">
                        <div style="flex:1;min-width:0;">
                            <h2 style="font-weight:600;color:#fff;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="site.name"></h2>
                            <div style="display:flex;align-items:center;gap:8px;margin-top:6px;font-size:12px;">
                                <span style="color:#6b7280;font-family:'DM Mono',monospace;" x-text="site.altitude+' m'"></span>
                                <span style="color:#374151;">·</span>
                                <span style="padding:2px 8px;border-radius:999px;border:1px solid;font-size:11px;"
                                      :style="{borderColor:site.level==='initiation'?'rgba(56,189,248,.3)':site.level==='debutant'?'rgba(74,222,128,.3)':site.level==='intermediaire'?'rgba(250,204,21,.3)':site.level==='confirme'?'rgba(251,146,60,.3)':'rgba(248,113,113,.3)',color:site.level==='initiation'?'#38bdf8':site.level==='debutant'?'#4ade80':site.level==='intermediaire'?'#facc15':site.level==='confirme'?'#fb923c':'#f87171'}"
                                      x-text="site.level"></span>
                            </div>
                        </div>
                        <button @click="closePanel()" style="width:28px;height:28px;border-radius:50%;border:none;background:transparent;color:#6b7280;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-left:8px;" onmouseover="this.style.background='rgba(55,65,81,.7)';this.style.color='#fff'" onmouseout="this.style.background='transparent';this.style.color='#6b7280'">✕</button>
                    </div>
                    <div x-show="currentSunWindow" style="margin-top:12px;display:flex;align-items:center;gap:8px;font-size:12px;color:#6b7280;">
                        <span style="color:#fbbf24;">☀</span>
                        <span>Vol de <span style="color:#e5e7eb;font-family:'DM Mono',monospace;" x-text="currentSunWindow?.start_hour+'h'"></span> à <span style="color:#e5e7eb;font-family:'DM Mono',monospace;" x-text="currentSunWindow?.end_hour+'h'"></span></span>
                        <span style="color:#374151;">·</span>
                        <span x-text="currentSunWindow?.sunrise_display+' → '+currentSunWindow?.sunset_display"></span>
                    </div>
                </div>

                <div x-show="loading" style="flex:1;display:flex;align-items:center;justify-content:center;">
                    <div style="width:28px;height:28px;border:2px solid #374151;border-top-color:#38bdf8;border-radius:50%;animation:spin 1s linear infinite;"></div>
                </div>

                <div x-show="!loading" style="flex:1;overflow-y:auto;min-height:0;">
                    <div style="padding:16px 20px 8px;">
                        <div style="font-size:10px;color:#4b5563;text-transform:uppercase;letter-spacing:.08em;font-weight:500;margin-bottom:12px;">Fenêtres de vol</div>
                        <div x-show="Object.keys(scoresByDay).length>0" style="display:flex;flex-direction:column;gap:8px;">
                            <template x-for="(slots,day) in scoresByDay" :key="day">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <span style="font-size:10px;color:#4b5563;font-family:'DM Mono',monospace;width:36px;flex-shrink:0;" x-text="day"></span>
                                    <div style="display:flex;gap:1px;flex:1;align-items:stretch;height:20px;">
                                        <template x-for="slot in slots" :key="slot.forecast_at">
                                            <div class="slot-block" :class="selectedSlot===slot.forecast_at?'sel':''" :style="`background:${slotColor(slot)}`" :title="slot.hour+' · '+statusLabel(slot.status)+' · '+slot.confidence+'%'" @click="selectSlot(slot)"></div>
                                        </template>
                                    </div>
                                    <span style="font-size:10px;width:20px;text-align:right;flex-shrink:0;font-family:'DM Mono',monospace;" :style="{color:greenHours(slots)>0?'#4ade80':'#374151'}" x-text="greenHours(slots)>0?greenHours(slots)+'h':''"></span>
                                </div>
                            </template>
                        </div>
                        <div x-show="Object.keys(scoresByDay).length===0" style="padding:24px;text-align:center;font-size:12px;color:#4b5563;">Aucune prévision disponible.</div>
                    </div>

                    <div x-show="!selectedSlotData&&Object.keys(scoresByDay).length>0" style="margin:0 20px 12px;border:1px dashed rgba(55,65,81,.5);border-radius:12px;padding:12px;text-align:center;font-size:12px;color:#4b5563;">↑ Cliquez sur un bloc pour voir le détail</div>

                    <div x-show="selectedSlotData" style="margin:0 20px 12px;background:rgba(31,41,55,.4);border:1px solid rgba(55,65,81,.4);border-radius:12px;padding:16px;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                            <span style="font-size:14px;font-weight:500;color:#fff;" x-text="selectedSlotData?.day+' à '+selectedSlotData?.hour"></span>
                            <span style="font-size:11px;padding:4px 10px;border-radius:999px;font-weight:500;border:1px solid;"
                                  :style="{background:selectedSlotData?.status==='green'?'rgba(34,197,94,.12)':selectedSlotData?.status==='orange'?'rgba(245,158,11,.12)':selectedSlotData?.status==='red'?'rgba(239,68,68,.12)':'rgba(75,85,99,.3)',borderColor:selectedSlotData?.status==='green'?'rgba(34,197,94,.25)':selectedSlotData?.status==='orange'?'rgba(245,158,11,.25)':selectedSlotData?.status==='red'?'rgba(239,68,68,.25)':'rgba(75,85,99,.4)',color:selectedSlotData?.status==='green'?'#4ade80':selectedSlotData?.status==='orange'?'#fbbf24':selectedSlotData?.status==='red'?'#f87171':'#9ca3af'}"
                                  x-text="statusLabel(selectedSlotData?.status)"></span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div style="background:rgba(0,0,0,.3);border-radius:12px;padding:12px;text-align:center;">
                                <div style="font-size:20px;margin-bottom:6px;display:inline-block;transition:transform .3s;" :style="`transform:rotate(${(selectedSlotData?.wind_dir??0)+180}deg)`">↑</div>
                                <div style="font-size:10px;color:#6b7280;margin-bottom:2px;">Direction</div>
                                <div style="font-size:14px;font-weight:600;color:#fff;font-family:'DM Mono',monospace;" x-text="selectedSlotData?.wind_dir?selectedSlotData.wind_dir+'°':'—'"></div>
                            </div>
                            <div style="background:rgba(0,0,0,.3);border-radius:12px;padding:12px;text-align:center;">
                                <div style="font-size:20px;margin-bottom:6px;">💨</div>
                                <div style="font-size:10px;color:#6b7280;margin-bottom:2px;">Vent moy.</div>
                                <div style="font-size:14px;font-weight:600;color:#fff;font-family:'DM Mono',monospace;" x-text="selectedSlotData?.wind_speed?selectedSlotData.wind_speed+' km/h':'—'"></div>
                            </div>
                            <div style="background:rgba(0,0,0,.3);border-radius:12px;padding:12px;">
                                <div style="font-size:10px;color:#6b7280;margin-bottom:8px;">Confiance</div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <div style="flex:1;height:6px;background:#1f2937;border-radius:3px;overflow:hidden;">
                                        <div style="height:100%;border-radius:3px;transition:width .5s;" :style="{width:(selectedSlotData?.confidence??0)+'%',background:selectedSlotData?.confidence>=75?'#22c55e':selectedSlotData?.confidence>=50?'#f59e0b':'#ef4444'}"></div>
                                    </div>
                                    <span style="font-size:12px;font-weight:600;color:#fff;font-family:'DM Mono',monospace;" x-text="(selectedSlotData?.confidence??0)+'%'"></span>
                                </div>
                                <div style="font-size:10px;color:#4b5563;" x-text="(selectedSlotData?.models_conv??0)+'/'+(selectedSlotData?.models_count??0)+' modèles'"></div>
                            </div>
                            <div style="background:rgba(0,0,0,.3);border-radius:12px;padding:12px;">
                                <div style="font-size:10px;color:#6b7280;margin-bottom:8px;">Précipitations</div>
                                <div style="font-size:14px;font-weight:600;font-family:'DM Mono',monospace;" :style="{color:(selectedSlotData?.precip??0)>0?'#60a5fa':'#4ade80'}" x-text="selectedSlotData?.precip!==null?selectedSlotData.precip+' mm/h':'—'"></div>
                                <div style="font-size:10px;margin-top:4px;" :style="{color:(selectedSlotData?.precip??0)===0?'#16a34a':'#dc2626'}" x-text="(selectedSlotData?.precip??0)===0?'✓ Sec':'✗ Pluie'"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="padding:10px 20px;border-top:1px solid rgba(55,65,81,.4);flex-shrink:0;display:flex;justify-content:space-between;font-size:10px;color:#374151;">
                    <span>Open-Meteo · 10 modèles météo</span><span>Horizon 5 jours</span>
                </div>
            </div>
        </div>

        {{-- ═══ DROPDOWNS (position:fixed, hors stacking context) ══ --}}
        {{-- Rendus dans le même x-data que mapApp() → accès direct aux données --}}

        {{-- Dropdown journée --}}
        <div x-show="dayDropOpen" class="dd-menu"
             :style="`top:${dayDropPos.top}px;left:${dayDropPos.left}px;min-width:260px;`"
             @click.stop>
            <template x-for="(day,idx) in days" :key="idx">
                <button class="dd-item" :class="selectedDayIdx===idx?'is-active':''"
                        @click="selectDay(idx);dayDropOpen=false">
                <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;"
                      :style="{background:day.bestStatus==='green'?'#22c55e':day.bestStatus==='orange'?'#f59e0b':day.bestStatus==='red'?'#ef4444':'#6b7280'}"></span>
                    <span style="flex:1;font-weight:500;" x-text="day.label"></span>
                    <span x-show="day.greenSlots>0" style="color:#4ade80;font-size:11px;font-family:'DM Mono',monospace;" x-text="day.greenSlots+'h'"></span>
                    <span x-show="!day.greenSlots" style="color:#374151;font-size:11px;">—</span>
                    <span x-show="selectedDayIdx===idx" style="color:#4ade80;margin-left:4px;">✓</span>
                </button>
            </template>
        </div>

        {{-- Dropdown fond de carte --}}
        <div x-show="bmDropOpen" class="dd-menu"
             :style="`top:${bmDropPos.top}px;right:${bmDropPos.right}px;width:230px;`"
             @click.stop>
            <template x-for="bm in basemapList" :key="bm.key">
                <button class="dd-item" :class="currentBasemap===bm.key?'is-active':''"
                        @click="switchBasemap(bm.key);bmDropOpen=false">
                    <span x-text="bm.icon" style="font-size:16px;width:20px;text-align:center;flex-shrink:0;"></span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:500;" x-text="bm.label"></div>
                        <div class="dd-sub" x-text="bm.desc"></div>
                    </div>
                    <span x-show="currentBasemap===bm.key" style="color:#4ade80;">✓</span>
                </button>
            </template>
        </div>

    </div>{{-- fin pg-app --}}
@endsection

@push('styles')
    <script>
        const BASEMAP_LIST=[
            {key:'topo',label:'Topographique',icon:'⛰',desc:'Relief & courbes de niveau',url:'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',attribution:'© OpenStreetMap contributors, © OpenTopoMap',maxZoom:17},
            {key:'osm',label:'Standard',icon:'🗺',desc:'OpenStreetMap classique',url:'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',attribution:'© OpenStreetMap contributors',maxZoom:19},
            {key:'satellite',label:'Satellite',icon:'🛰',desc:'Vue aérienne ESRI',url:'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',attribution:'© Esri, Maxar',maxZoom:18},
            {key:'dark',label:'Sombre',icon:'🌙',desc:'CartoDB Dark Matter',url:'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',attribution:'© OpenStreetMap © CARTO',maxZoom:19},
            {key:'light',label:'Clair',icon:'☀',desc:'CartoDB Voyager',url:'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',attribution:'© OpenStreetMap © CARTO',maxZoom:19},
        ];

        function pgIcon(c){return`<svg class="pg-marker" width="36" height="40" viewBox="0 0 36 40" fill="none"><path d="M18 2 L34 8 L34 22 Q34 34 18 38 Q2 34 2 22 L2 8 Z" fill="${c}" stroke="white" stroke-width="1.5"/><path d="M8 18 Q10 10 18 9 Q26 10 28 18" stroke="white" stroke-width="1.8" fill="rgba(255,255,255,.2)" stroke-linecap="round"/><line x1="12" y1="17" x2="18" y2="24" stroke="white" stroke-width="1.2"/><line x1="18" y1="11" x2="18" y2="24" stroke="white" stroke-width="1.2"/><line x1="24" y1="17" x2="18" y2="24" stroke="white" stroke-width="1.2"/><circle cx="18" cy="27" r="2.5" fill="white"/></svg>`;}

        const SC={green:'#16a34a',orange:'#d97706',red:'#dc2626',unknown:'#4b5563'};
        const DF=['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];

        function mapApp(){return{
            map:null,tl:null,markers:{},
            sites:[],allScores:{},sunWindows:{},
            days:[],selectedDayIdx:0,
            site:{},loading:false,
            scoresByDay:{},selectedSlot:null,selectedSlotData:null,
            currentBasemap:'topo',basemapList:BASEMAP_LIST,
            dayDropOpen:false,dayDropPos:{top:0,left:0},
            bmDropOpen:false,bmDropPos:{top:0,right:0},

            async init(){await this.$nextTick();this.initMap();await this.loadSites();},

            initMap(){
                this.map=L.map('map',{center:[49.1,5.5],zoom:7});
                const b=BASEMAP_LIST[0];
                this.tl=L.tileLayer(b.url,{attribution:b.attribution,maxZoom:b.maxZoom}).addTo(this.map);
            },

            toggleDayDrop(btn){
                if(!this.dayDropOpen){const r=btn.getBoundingClientRect();this.dayDropPos={top:r.bottom+6,left:r.left};}
                this.dayDropOpen=!this.dayDropOpen;
                this.bmDropOpen=false;
            },

            toggleBmDrop(btn){
                if(!this.bmDropOpen){const r=btn.getBoundingClientRect();this.bmDropPos={top:r.bottom+6,right:window.innerWidth-r.right};}
                this.bmDropOpen=!this.bmDropOpen;
                this.dayDropOpen=false;
            },

            switchBasemap(key){
                if(key===this.currentBasemap)return;
                const b=BASEMAP_LIST.find(x=>x.key===key);
                if(!b)return;
                this.map.removeLayer(this.tl);
                this.tl=L.tileLayer(b.url,{attribution:b.attribution,maxZoom:b.maxZoom}).addTo(this.map);
                this.currentBasemap=key;
            },

            get currentBasemapObj(){return BASEMAP_LIST.find(b=>b.key===this.currentBasemap)??BASEMAP_LIST[0];},

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

            selectDay(idx){this.selectedDayIdx=idx;this.renderMarkers();},

            get greenCount(){const day=this.days[this.selectedDayIdx]?.raw;if(!day)return 0;return this.sites.filter(s=>(this.allScores[s.id]||[]).some(sc=>sc.day===day&&sc.status==='green')).length;},

            get currentSunWindow(){if(!this.site?.id)return null;return this.sunWindows[this.site.id]?.[this.days[this.selectedDayIdx]?.raw]??null;},

            renderMarkers(){
                const day=this.days[this.selectedDayIdx]?.raw;
                this.sites.forEach(site=>{
                    const scores=(this.allScores[site.id]||[]).filter(s=>s.day===day);
                    let st='unknown';
                    if(scores.some(s=>s.status==='green'))st='green';
                    else if(scores.some(s=>s.status==='orange'))st='orange';
                    else if(scores.length>0)st='red';
                    const icon=L.divIcon({className:'',html:pgIcon(SC[st]),iconSize:[36,40],iconAnchor:[18,20]});
                    if(this.markers[site.id]){
                        const was=this.markers[site.id].getElement()?.querySelector('.pg-marker')?.classList.contains('selected');
                        this.markers[site.id].setIcon(icon);
                        if(was)setTimeout(()=>this.markers[site.id]?.getElement()?.querySelector('.pg-marker')?.classList.add('selected'),10);
                    }else{
                        const mk=L.marker([site.lat,site.lng],{icon}).addTo(this.map).bindTooltip(site.name,{permanent:false,direction:'top',offset:[0,-16]});
                        mk.on('click',()=>this.openSite(site));
                        this.markers[site.id]=mk;
                    }
                });
            },

            openSite(site){
                this.site=site;this.loading=false;this.selectedSlot=null;this.selectedSlotData=null;
                Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-marker')?.classList.remove('selected'));
                this.markers[site.id]?.getElement()?.querySelector('.pg-marker')?.classList.add('selected');
                this.scoresByDay=(this.allScores[site.id]||[]).reduce((acc,s)=>{if(!acc[s.day])acc[s.day]=[];acc[s.day].push(s);return acc;},{});
                document.getElementById('panel').classList.add('open');
            },

            closePanel(){
                document.getElementById('panel').classList.remove('open');
                Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-marker')?.classList.remove('selected'));
                this.site={};
            },

            selectSlot(slot){this.selectedSlot=slot.forecast_at;this.selectedSlotData=slot;},

            slotColor(slot){const a=Math.max(0.25,(slot.confidence??50)/100);return{green:`rgba(22,163,74,${a})`,orange:`rgba(217,119,6,${a})`,red:`rgba(220,38,38,${a})`,unknown:'rgba(75,85,99,0.4)'}[slot.status]??'rgba(75,85,99,0.4)';},

            statusLabel(s){return{green:'Favorable',orange:'Incertain',red:'Défavorable',unknown:'—'}[s]??'—';},

            greenHours(slots){return slots.filter(s=>s.status==='green').length;},
        };}
    </script>
@endpush
