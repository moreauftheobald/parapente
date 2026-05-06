@extends('layouts.app')

@section('title', 'Carte météo parapente — Grand Est')

@push('styles')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'DM Sans', sans-serif; }
        .mono { font-family: 'DM Mono', monospace; }

        #map { height: 100%; width: 100%; }

        .site-marker {
            width: 36px !important; height: 36px !important;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.12);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            box-shadow: 0 4px 14px rgba(0,0,0,0.5);
            position: relative;
        }
        .site-marker:hover { transform: scale(1.2); border-color: rgba(255,255,255,0.5); }
        .site-marker .conf { font-size: 9px; font-weight: 600; color: white; font-family: 'DM Mono', monospace; }
        .marker-selected { border: 2px solid white !important; box-shadow: 0 0 0 3px rgba(255,255,255,0.25), 0 6px 20px rgba(0,0,0,0.6) !important; transform: scale(1.2); z-index: 999 !important; }

        #panel { transition: transform 0.35s cubic-bezier(0.4,0,0.2,1); transform: translateX(100%); }
        #panel.open { transform: translateX(0); }

        .slot-block { flex: 1; height: 20px; min-width: 2px; cursor: pointer; border-radius: 2px; transition: opacity 0.1s, transform 0.1s; }
        .slot-block:hover { opacity: 0.75; transform: scaleY(1.2); }
        .slot-block.active { outline: 2px solid white; outline-offset: 1px; z-index: 1; }

        .day-tab { transition: all 0.18s; }
        .day-tab.active { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.2) !important; }

        ::-webkit-scrollbar { width: 3px; }
        ::-webkit-scrollbar-thumb { background: #2d3748; border-radius: 2px; }

        .leaflet-tooltip {
            background: #0f172a !important; border: 1px solid #1e293b !important;
            color: #cbd5e1 !important; font-family: 'DM Sans', sans-serif !important;
            font-size: 12px !important; padding: 5px 10px !important;
            border-radius: 8px !important; box-shadow: 0 4px 16px rgba(0,0,0,0.5) !important;
        }
        .leaflet-tooltip-top:before { border-top-color: #1e293b !important; }
        .leaflet-control-zoom a { background-color: #1e293b !important; color: #64748b !important; border-color: #334155 !important; }
        .leaflet-control-zoom a:hover { background-color: #334155 !important; color: white !important; }
        .leaflet-bar { border-color: #334155 !important; box-shadow: 0 2px 8px rgba(0,0,0,0.4) !important; }
    </style>
@endpush

@section('content')
    <div class="relative h-full w-full" x-data="mapApp()" x-init="init()">

        <div id="map" class="absolute inset-0"></div>

        {{-- Barre de jours --}}
        <div class="absolute top-3 left-1/2 -translate-x-1/2 z-[1000]
                flex items-center gap-1 bg-gray-900/95 backdrop-blur border border-gray-700/50
                rounded-2xl px-2 py-1.5 shadow-2xl shadow-black/50">
            <template x-for="(day, idx) in days" :key="idx">
                <button @click="selectDay(idx)"
                        class="day-tab flex items-center gap-2 px-3 py-1.5 rounded-xl border border-transparent text-sm"
                        :class="selectedDayIdx === idx ? 'active' : 'hover:bg-white/5'">
                <span class="w-2 h-2 rounded-full shrink-0"
                      :class="{
                          'bg-green-500':  day.bestStatus === 'green',
                          'bg-orange-400': day.bestStatus === 'orange',
                          'bg-red-500':    day.bestStatus === 'red',
                          'bg-gray-600':   day.bestStatus === 'unknown',
                      }"></span>
                    <span class="text-gray-200" x-text="day.label"></span>
                    <span x-show="day.greenSlots > 0" class="text-[10px] text-green-400 mono" x-text="day.greenSlots + 'h'"></span>
                </button>
            </template>
        </div>

        {{-- Légende --}}
        <div class="absolute bottom-6 left-3 z-[1000] bg-gray-900/90 backdrop-blur border border-gray-700/40 rounded-xl p-3 text-xs shadow-xl">
            <div class="text-gray-600 uppercase tracking-widest text-[9px] font-medium mb-2.5">Légende</div>
            <div class="space-y-1.5">
                <div class="flex items-center gap-2.5"><span class="w-2.5 h-2.5 rounded-full bg-green-500"></span><span class="text-gray-400">Favorable</span></div>
                <div class="flex items-center gap-2.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-400"></span><span class="text-gray-400">Incertain</span></div>
                <div class="flex items-center gap-2.5"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span><span class="text-gray-400">Défavorable</span></div>
                <div class="flex items-center gap-2.5"><span class="w-2.5 h-2.5 rounded-full bg-gray-600"></span><span class="text-gray-400">Sans données</span></div>
            </div>
            <div class="mt-2.5 pt-2 border-t border-gray-700/40 text-[9px] text-gray-600">% = indice de confiance</div>
        </div>

        {{-- Compteur --}}
        <div class="absolute bottom-6 left-1/2 -translate-x-1/2 z-[1000]
                bg-gray-900/80 backdrop-blur border border-gray-700/40 rounded-full
                px-4 py-1.5 text-xs text-gray-400 pointer-events-none shadow-lg">
            <span class="text-green-400 font-medium" x-text="greenCount"></span>
            site<span x-show="greenCount !== 1">s</span> volabl<span x-show="greenCount !== 1">es</span><span x-show="greenCount === 1">e</span>
            sur <span x-text="sites.length"></span> ·
            <span x-text="selectedDayLabel"></span>
        </div>

        {{-- Panel site --}}
        <div id="panel" class="absolute top-0 right-0 h-full w-96 bg-gray-900 border-l border-gray-700/50 z-[1000] flex flex-col shadow-2xl">

            {{-- Header --}}
            <div class="px-5 pt-5 pb-4 border-b border-gray-700/40 shrink-0">
                <div class="flex items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <h2 class="font-semibold text-white truncate" x-text="site.name"></h2>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span class="text-xs text-gray-500 mono" x-text="site.altitude + ' m'"></span>
                            <span class="text-gray-700 text-xs">·</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full border"
                                  :class="{
                                  'border-sky-800/60 text-sky-400':      site.level === 'initiation',
                                  'border-green-800/60 text-green-400':  site.level === 'debutant',
                                  'border-yellow-800/60 text-yellow-400':site.level === 'intermediaire',
                                  'border-orange-800/60 text-orange-400':site.level === 'confirme',
                                  'border-red-800/60 text-red-400':      site.level === 'expert',
                              }"
                                  x-text="site.level">
                        </span>
                        </div>
                    </div>
                    <button @click="closePanel()" class="ml-2 w-7 h-7 flex items-center justify-center rounded-full text-gray-600 hover:text-white hover:bg-gray-700/70 transition shrink-0">✕</button>
                </div>
            </div>

            {{-- Loader --}}
            <div x-show="loading" class="flex-1 flex items-center justify-center">
                <div class="w-7 h-7 border-2 border-gray-700 border-t-sky-500 rounded-full animate-spin"></div>
            </div>

            {{-- Contenu --}}
            <div x-show="!loading" class="flex-1 overflow-y-auto min-h-0">

                {{-- Timeline 5 jours --}}
                <div class="px-5 pt-4 pb-2">
                    <div class="text-[10px] text-gray-600 uppercase tracking-widest font-medium mb-3">Fenêtres de vol</div>

                    <div x-show="Object.keys(scoresByDay).length > 0" class="space-y-2">
                        <template x-for="(slots, day) in scoresByDay" :key="day">
                            <div class="flex items-center gap-2.5">
                                <span class="text-[10px] text-gray-600 mono w-9 shrink-0" x-text="day"></span>
                                <div class="flex gap-px flex-1 items-stretch" style="height:20px">
                                    <template x-for="slot in slots" :key="slot.forecast_at">
                                        <div class="slot-block"
                                             :class="selectedSlot === slot.forecast_at ? 'active' : ''"
                                             :style="`background: ${slotColor(slot)}`"
                                             :title="slot.hour + ' · ' + statusLabel(slot.status) + ' · ' + slot.confidence + '%'"
                                             @click="selectSlot(slot)">
                                        </div>
                                    </template>
                                </div>
                                <span class="text-[10px] mono w-5 text-right shrink-0"
                                      :class="greenHours(slots) > 0 ? 'text-green-500' : 'text-gray-700'"
                                      x-text="greenHours(slots) > 0 ? greenHours(slots) + 'h' : ''">
                            </span>
                            </div>
                        </template>
                    </div>

                    <div x-show="Object.keys(scoresByDay).length === 0" class="py-6 text-center text-xs text-gray-600">
                        Aucune prévision disponible.<br>
                        <span class="text-gray-700">Le fetch météo n'a pas encore tourné.</span>
                    </div>
                </div>

                {{-- Invite --}}
                <div x-show="!selectedSlotData && Object.keys(scoresByDay).length > 0"
                     class="mx-5 my-3 rounded-xl border border-dashed border-gray-700/50 px-4 py-3 text-center text-xs text-gray-600">
                    ↑ Cliquez sur un bloc pour voir le détail
                </div>

                {{-- Détail créneau --}}
                <div x-show="selectedSlotData" class="mx-5 my-3 rounded-xl bg-gray-800/40 border border-gray-700/40 p-4">

                    <div class="flex items-center justify-between mb-4">
                    <span class="text-sm font-medium text-white"
                          x-text="selectedSlotData?.day + ' à ' + selectedSlotData?.hour"></span>
                        <span class="text-xs px-2.5 py-1 rounded-full font-medium"
                              :class="{
                              'bg-green-500/15 text-green-400 border border-green-500/20':  selectedSlotData?.status === 'green',
                              'bg-orange-500/15 text-orange-400 border border-orange-500/20': selectedSlotData?.status === 'orange',
                              'bg-red-500/15 text-red-400 border border-red-500/20':         selectedSlotData?.status === 'red',
                              'bg-gray-700/50 text-gray-400 border border-gray-700':         selectedSlotData?.status === 'unknown',
                          }"
                              x-text="statusLabel(selectedSlotData?.status)">
                    </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2.5">

                        {{-- Direction --}}
                        <div class="bg-gray-900/50 rounded-xl p-3 text-center">
                            <div class="text-xl mb-1.5 transition-transform duration-300"
                                 :style="`display:inline-block; transform: rotate(${(selectedSlotData?.wind_dir ?? 0) + 180}deg)`">↑</div>
                            <div class="text-[10px] text-gray-500 mb-0.5">Direction</div>
                            <div class="text-sm font-semibold text-white mono"
                                 x-text="selectedSlotData?.wind_dir ? selectedSlotData.wind_dir + '°' : '—'"></div>
                        </div>

                        {{-- Vitesse --}}
                        <div class="bg-gray-900/50 rounded-xl p-3 text-center">
                            <div class="text-xl mb-1.5">💨</div>
                            <div class="text-[10px] text-gray-500 mb-0.5">Vent moy.</div>
                            <div class="text-sm font-semibold text-white mono"
                                 x-text="selectedSlotData?.wind_speed ? selectedSlotData.wind_speed + ' km/h' : '—'"></div>
                        </div>

                        {{-- Confiance --}}
                        <div class="bg-gray-900/50 rounded-xl p-3">
                            <div class="text-[10px] text-gray-500 mb-2">Confiance</div>
                            <div class="flex items-center gap-2 mb-1">
                                <div class="flex-1 h-1.5 bg-gray-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500"
                                         :class="{
                                         'bg-green-500': selectedSlotData?.confidence >= 75,
                                         'bg-orange-400': selectedSlotData?.confidence >= 50 && selectedSlotData?.confidence < 75,
                                         'bg-red-500': selectedSlotData?.confidence < 50,
                                     }"
                                         :style="`width:${selectedSlotData?.confidence ?? 0}%`"></div>
                                </div>
                                <span class="text-xs font-semibold mono text-white" x-text="(selectedSlotData?.confidence ?? 0) + '%'"></span>
                            </div>
                            <div class="text-[10px] text-gray-600"
                                 x-text="(selectedSlotData?.models_conv ?? 0) + '/' + (selectedSlotData?.models_count ?? 0) + ' modèles'"></div>
                        </div>

                        {{-- Précipitations --}}
                        <div class="bg-gray-900/50 rounded-xl p-3">
                            <div class="text-[10px] text-gray-500 mb-2">Précipitations</div>
                            <div class="text-sm font-semibold mono"
                                 :class="(selectedSlotData?.precip ?? 0) > 0 ? 'text-blue-400' : 'text-green-400'"
                                 x-text="selectedSlotData?.precip !== null ? selectedSlotData.precip + ' mm/h' : '—'"></div>
                            <div class="text-[10px] mt-1"
                                 :class="(selectedSlotData?.precip ?? 0) === 0 ? 'text-green-600' : 'text-red-500'"
                                 x-text="(selectedSlotData?.precip ?? 0) === 0 ? '✓ Sec' : '✗ Pluie'"></div>
                        </div>

                    </div>
                </div>

            </div>

            {{-- Footer --}}
            <div class="px-5 py-3 border-t border-gray-700/40 shrink-0 flex justify-between text-[10px] text-gray-600">
                <span>Open-Meteo · 10 modèles météo</span>
                <span>Horizon 5 jours</span>
            </div>
        </div>

    </div>
@endsection

@push('styles')
    <script>
        const STATUS_COLORS = { green:'#16a34a', orange:'#ea580c', red:'#dc2626', unknown:'#374151' };
        const DAYS_FR = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];

        function mapApp() {
            return {
                map: null, markers: {}, sites: [], allScores: {},
                days: [], selectedDayIdx: 0,
                siteSelected: false, loading: false, site: {},
                scoresByDay: {}, selectedSlot: null, selectedSlotData: null,

                async init() {
                    await this.$nextTick();
                    this.initMap();
                    await this.loadSites();
                },

                initMap() {
                    this.map = L.map('map', { center:[49.1,5.5], zoom:7, zoomControl:true });
                    L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors, © OpenTopoMap (CC-BY-SA)',
                        maxZoom: 17,
                    }).addTo(this.map);
                },

                async loadSites() {
                    try {
                        const resp = await fetch('/api/sites');
                        this.sites = await resp.json();
                        await Promise.all(this.sites.map(s => this.loadSiteScores(s.id)));
                        this.buildDays();
                        this.renderMarkers();
                    } catch(e) { console.error(e); }
                },

                async loadSiteScores(id) {
                    try {
                        const r = await fetch(`/api/sites/${id}/scores`);
                        const d = await r.json();
                        this.allScores[id] = d.scores || [];
                    } catch(e) { this.allScores[id] = []; }
                },

                buildDays() {
                    const map = {};
                    Object.values(this.allScores).flat().forEach(s => {
                        if (!map[s.day]) map[s.day] = [];
                        map[s.day].push(s);
                    });
                    this.days = Object.keys(map).slice(0,5).map((day,i) => {
                        const slots = map[day];
                        const hasGreen  = slots.some(s => s.status==='green');
                        const hasOrange = slots.some(s => s.status==='orange');
                        // Créneaux verts uniques (par heure, tous sites confondus)
                        const greenSlots = [...new Set(
                            Object.values(this.allScores).flat()
                                .filter(s => s.day===day && s.status==='green')
                                .map(s => s.hour)
                        )].length;
                        // Label
                        const [d,m] = day.split('/');
                        const dt = new Date(new Date().getFullYear(), parseInt(m)-1, parseInt(d));
                        const now = new Date();
                        const tom = new Date(now); tom.setDate(now.getDate()+1);
                        let label = dt.toDateString()===now.toDateString() ? "Auj."
                            : dt.toDateString()===tom.toDateString() ? "Dem."
                                : DAYS_FR[dt.getDay()]+' '+d;
                        return {
                            label, raw:day,
                            bestStatus: hasGreen?'green': hasOrange?'orange':'red',
                            greenSlots,
                        };
                    });
                },

                selectDay(idx) {
                    this.selectedDayIdx = idx;
                    this.renderMarkers();
                },

                get selectedDayLabel() { return this.days[this.selectedDayIdx]?.label ?? ''; },

                get greenCount() {
                    const day = this.days[this.selectedDayIdx]?.raw;
                    if (!day) return 0;
                    return this.sites.filter(s =>
                        (this.allScores[s.id]||[]).some(sc => sc.day===day && sc.status==='green')
                    ).length;
                },

                renderMarkers() {
                    const day = this.days[this.selectedDayIdx]?.raw;
                    this.sites.forEach(site => {
                        const scores = (this.allScores[site.id]||[]).filter(s => s.day===day);
                        let status='unknown', conf=0, windDir=null;

                        if (scores.some(s=>s.status==='green')) {
                            const best = scores.filter(s=>s.status==='green').sort((a,b)=>b.confidence-a.confidence)[0];
                            status='green'; conf=best.confidence; windDir=best.wind_dir;
                        } else if (scores.some(s=>s.status==='orange')) {
                            status='orange'; conf=scores.find(s=>s.status==='orange').confidence;
                        } else if (scores.length>0) { status='red'; }

                        const color = STATUS_COLORS[status];
                        const label = conf > 0 ? conf+'%' : '?';
                        const arrowHtml = windDir!==null
                            ? `<div style="position:absolute;top:-7px;left:50%;font-size:8px;color:rgba(255,255,255,0.6);transform:translateX(-50%) rotate(${windDir+180}deg)">▲</div>`
                            : '';

                        const html = `<div class="site-marker" style="background:${color}">${arrowHtml}<span class="conf">${label}</span></div>`;
                        const icon = L.divIcon({ className:'', html, iconSize:[36,36], iconAnchor:[18,18] });

                        if (this.markers[site.id]) {
                            this.markers[site.id].setIcon(icon);
                        } else {
                            const m = L.marker([site.lat,site.lng],{icon})
                                .addTo(this.map)
                                .bindTooltip(site.name,{permanent:false,direction:'top',offset:[0,-14]});
                            m.on('click',()=>this.openSite(site));
                            this.markers[site.id]=m;
                        }
                    });
                },

                openSite(site) {
                    this.siteSelected=true; this.loading=false; this.site=site;
                    this.selectedSlot=null; this.selectedSlotData=null;
                    Object.values(this.markers).forEach(m=>
                        m.getElement()?.querySelector('.site-marker')?.classList.remove('marker-selected'));
                    this.markers[site.id]?.getElement()?.querySelector('.site-marker')?.classList.add('marker-selected');
                    const scores = this.allScores[site.id]||[];
                    this.scoresByDay = scores.reduce((acc,s)=>{
                        if(!acc[s.day]) acc[s.day]=[];
                        acc[s.day].push(s); return acc;
                    },{});
                    document.getElementById('panel').classList.add('open');
                },

                closePanel() {
                    this.siteSelected=false;
                    document.getElementById('panel').classList.remove('open');
                    Object.values(this.markers).forEach(m=>
                        m.getElement()?.querySelector('.site-marker')?.classList.remove('marker-selected'));
                },

                selectSlot(slot) { this.selectedSlot=slot.forecast_at; this.selectedSlotData=slot; },

                slotColor(slot) {
                    const a = Math.max(0.25, (slot.confidence??50)/100);
                    return {
                        green:  `rgba(22,163,74,${a})`,
                        orange: `rgba(234,88,12,${a})`,
                        red:    `rgba(220,38,38,${a})`,
                        unknown:'rgba(55,65,81,0.4)',
                    }[slot.status] ?? 'rgba(55,65,81,0.4)';
                },

                statusLabel(s) {
                    return {green:'Favorable',orange:'Incertain',red:'Défavorable',unknown:'—'}[s]??'—';
                },

                greenHours(slots) { return slots.filter(s=>s.status==='green').length; },
            };
        }
    </script>
@endpush
