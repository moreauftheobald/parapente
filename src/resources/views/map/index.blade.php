@extends('layouts.app')
@section('title', 'Carte météo parapente — Grand Est')

@push('styles')
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family:'DM Sans',sans-serif; }
        .mono { font-family:'DM Mono',monospace; }
        #pg-app  { display:flex; flex-direction:column; height:100%; overflow:hidden; }
        #pg-toolbar { flex-shrink:0; height:56px; background:#111827; border-bottom:1px solid rgba(55,65,81,.6); display:flex; align-items:center; padding:0 16px; gap:12px; position:relative; z-index:100; }
        #map-wrap { flex:1 1 0%; min-height:0; overflow:hidden; position:relative; }
        #map { height:100%; width:100%; }

        .dd-trigger { display:flex; align-items:center; gap:10px; padding:8px 14px; border-radius:12px; cursor:pointer; background:#1f2937; border:1px solid rgba(75,85,99,.5); color:#e5e7eb; font-size:14px; transition:border-color .15s; }
        .dd-trigger:hover { border-color:rgba(156,163,175,.6); }
        .dd-arrow { font-size:10px; color:#6b7280; display:inline-block; transition:transform .2s; }
        .dd-arrow.open { transform:rotate(180deg); }
        .dd-menu { position:fixed; background:#111827; border:1px solid rgba(55,65,81,.7); border-radius:16px; box-shadow:0 24px 48px rgba(0,0,0,.85); padding:6px 0; z-index:99999; }
        .dd-item { display:flex; align-items:center; gap:12px; padding:10px 16px; cursor:pointer; transition:background .1s; font-size:13px; color:#9ca3af; width:100%; background:none; border:none; text-align:left; }
        .dd-item:hover { background:rgba(255,255,255,.06); color:#e5e7eb; }
        .dd-item.is-active { background:rgba(255,255,255,.1); color:#fff; }
        .dd-sub { font-size:11px; color:#4b5563; }

        .pg-marker { cursor:pointer; transition:transform .15s,filter .15s; filter:drop-shadow(0 3px 6px rgba(0,0,0,.45)); display:block; }
        .pg-marker:hover { transform:scale(1.15); filter:drop-shadow(0 4px 10px rgba(0,0,0,.6)); }
        .pg-marker.selected { transform:scale(1.2); filter:drop-shadow(0 0 6px rgba(255,255,255,.7)) drop-shadow(0 4px 10px rgba(0,0,0,.6)); }

        /* Popup chart */
        #chart-popup { position:fixed; z-index:2000; width:460px; background:#111827; border:1px solid rgba(255,255,255,.14); border-radius:16px; box-shadow:0 24px 64px rgba(0,0,0,.85); transition:opacity .2s; }

        /* Side panel */
        :root { --panel-width: clamp(600px, 50vw, 900px); }
        #panel { width:var(--panel-width); transition:transform .35s cubic-bezier(.4,0,.2,1); transform:translateX(100%); }
        #panel.open { transform:translateX(0); }

        .panel-header { padding:18px 20px 0; border-bottom:1px solid rgba(55,65,81,.4); flex-shrink:0; }
        .panel-titlebar { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .panel-titlebar h2 { font-size:16px; font-weight:600; color:#fff; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .panel-meta { display:flex; align-items:center; gap:8px; margin-top:6px; font-size:12px; color:#6b7280; font-family:'DM Mono',monospace; }
        .panel-meta .sep { color:#374151; }
        .panel-close { width:28px; height:28px; border-radius:50%; border:none; background:transparent; color:#6b7280; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all .15s; }
        .panel-close:hover { background:rgba(55,65,81,.7); color:#fff; }
        .panel-sun { margin-top:10px; display:flex; align-items:center; gap:8px; font-size:12px; color:#9ca3af; }
        .panel-sun .ico { color:#fbbf24; font-size:18px; line-height:1; }
        .panel-sun .mono { color:#e5e7eb; font-family:'DM Mono',monospace; }

        .panel-tabs { display:flex; gap:4px; margin-top:14px; }
        .pg-tab { padding:9px 16px; border:none; background:transparent; color:#6b7280; cursor:pointer; font-size:13px; font-weight:500; border-bottom:2px solid transparent; transition:color .15s,border-color .15s; }
        .pg-tab:hover:not(.active) { color:#9ca3af; }
        .pg-tab.active { color:#fff; border-bottom-color:#38bdf8; }

        .panel-day-row { display:flex; align-items:center; gap:10px; padding:14px 0 16px; }
        .pg-day-arrow { width:30px; height:30px; border-radius:50%; border:1px solid rgba(55,65,81,.5); background:rgba(31,41,55,.4); color:#9ca3af; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all .15s; }
        .pg-day-arrow:hover:not(:disabled) { color:#fff; border-color:rgba(75,85,99,.7); background:rgba(31,41,55,.7); }
        .pg-day-arrow:disabled { opacity:.35; cursor:not-allowed; }
        .panel-day-label { flex:1; text-align:center; font-size:14px; font-weight:500; color:#fff; }
        .panel-conformity { display:flex; align-items:center; gap:8px; font-size:11px; color:#6b7280; }
        .panel-conformity .bar { width:64px; height:5px; background:#1f2937; border-radius:3px; overflow:hidden; }
        .panel-conformity .fill { height:100%; background:#22c55e; transition:width .4s; }
        .panel-conformity .val { font-family:'DM Mono',monospace; color:#e5e7eb; min-width:32px; text-align:right; }

        .panel-legend { padding:10px 20px; background:rgba(17,24,39,.95); border-bottom:1px solid rgba(55,65,81,.4); display:flex; flex-wrap:wrap; gap:6px; flex-shrink:0; position:sticky; top:0; z-index:5; backdrop-filter:blur(6px); }
        .pg-chip { display:inline-flex; align-items:center; gap:6px; padding:3px 9px; border-radius:999px; background:rgba(31,41,55,.6); border:1px solid rgba(55,65,81,.5); font-size:10px; color:#cbd5e1; user-select:none; }
        .pg-chip-dot { width:9px; height:3px; border-radius:1px; flex-shrink:0; }
        .pg-chip-consensus { background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.18); color:#fff; font-weight:500; }
        .pg-chip-consensus .pg-chip-dot { background-image:repeating-linear-gradient(90deg,#fff 0 3px,transparent 3px 6px); height:2px; }

        .panel-loader { flex:1; display:flex; align-items:center; justify-content:center; }
        .panel-spinner { width:28px; height:28px; border:2px solid #374151; border-top-color:#38bdf8; border-radius:50%; animation:spin 1s linear infinite; }

        .panel-scroll { flex:1; overflow-y:auto; min-height:0; }
        .panel-placeholder { padding:60px 24px; text-align:center; font-size:13px; color:#4b5563; line-height:1.6; }
        .panel-placeholder strong { color:#9ca3af; font-weight:600; }

        .panel-footer { padding:10px 20px; border-top:1px solid rgba(55,65,81,.4); flex-shrink:0; display:flex; justify-content:space-between; font-size:10px; color:#374151; }

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
    <div id="pg-app" x-data="mapApp()" x-init="init()"
         @click.window="dayDropOpen=false; bmDropOpen=false;">

        {{-- ═══ TOOLBAR ════════════════════════════════ --}}
        <div id="pg-toolbar">
            <button class="dd-trigger" style="min-width:230px;" @click.stop="toggleDayDrop($el)">
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
            <div style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:50%;background:#22c55e;"></span>
                <span style="color:#4ade80;font-weight:500;" x-text="greenCount"></span>
                <span>/ <span x-text="sites.length"></span> volables</span>
            </div>
            <div style="flex:1;"></div>
            <div style="width:1px;height:24px;background:rgba(75,85,99,.4);"></div>
            <button class="dd-trigger" style="min-width:190px;" @click.stop="toggleBmDrop($el)">
                <span x-text="currentBasemapObj.icon" style="font-size:16px;line-height:1;flex-shrink:0;"></span>
                <span style="flex:1;text-align:left;" x-text="currentBasemapObj.label"></span>
                <span class="dd-arrow" :class="bmDropOpen?'open':''">▼</span>
            </button>
        </div>

        {{-- ═══ CARTE ═══════════════════════════════════ --}}
        <div id="map-wrap">
            <div id="map"></div>

            {{-- Légende --}}
            <div style="position:absolute;bottom:20px;left:12px;z-index:1000;background:rgba(17,24,39,.92);border:1px solid rgba(55,65,81,.4);border-radius:12px;padding:12px;font-size:12px;backdrop-filter:blur(8px);">
                <div style="color:#4b5563;text-transform:uppercase;letter-spacing:.08em;font-size:9px;font-weight:500;margin-bottom:10px;">Légende</div>
                <div style="display:flex;flex-direction:column;gap:7px;">
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#22c55e;"></span><span style="color:#9ca3af;">Favorable</span></div>
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;"></span><span style="color:#9ca3af;">Incertain</span></div>
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#ef4444;"></span><span style="color:#9ca3af;">Défavorable</span></div>
                    <div style="display:flex;align-items:center;gap:8px;"><span style="width:10px;height:10px;border-radius:50%;background:#6b7280;"></span><span style="color:#9ca3af;">Sans données</span></div>
                </div>
                <div style="margin-top:10px;padding-top:8px;border-top:1px solid rgba(55,65,81,.4);color:#374151;font-size:9px;">Lever−30min → Coucher+30min</div>
            </div>

            {{-- ── POPUP GRAPHIQUE ────────────────────────── --}}
            <div id="chart-popup" x-show="chartOpen"
                 :style="`top:${chartPos.top}px;left:${chartPos.left}px;`"
                 @click.stop>

                {{-- Header popup --}}
                <div style="padding:14px 16px 12px;border-bottom:1px solid rgba(55,65,81,.4);display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div style="color:#fff;font-size:14px;font-weight:500;" x-text="chartSite?.name"></div>
                        <div style="color:#6b7280;font-size:10px;margin-top:2px;">
                            <span x-text="chartSite?.altitude+' m'"></span> ·
                            <span x-text="chartSite?.level"></span> ·
                            <span style="color:#fbbf24;">☀</span>
                            <span x-text="chartSunWindow?.sunrise_display+' – '+chartSunWindow?.sunset_display"></span>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button @click="openPanel(); chartOpen=false;"
                                style="padding:5px 12px;border-radius:8px;border:1px solid rgba(55,65,81,.6);background:rgba(255,255,255,.05);color:#9ca3af;font-size:11px;cursor:pointer;transition:all .15s;"
                                onmouseover="this.style.background='rgba(255,255,255,.1)';this.style.color='#fff'"
                                onmouseout="this.style.background='rgba(255,255,255,.05)';this.style.color='#9ca3af'">
                            Détails ›
                        </button>
                        <button @click="chartOpen=false"
                                style="width:26px;height:26px;border-radius:50%;border:none;background:transparent;color:#6b7280;cursor:pointer;font-size:13px;"
                                onmouseover="this.style.background='rgba(55,65,81,.7)';this.style.color='#fff'"
                                onmouseout="this.style.background='transparent';this.style.color='#6b7280'">✕</button>
                    </div>
                </div>

                {{-- Loader --}}
                <div x-show="chartLoading" style="padding:40px;display:flex;align-items:center;justify-content:center;">
                    <div style="width:24px;height:24px;border:2px solid #374151;border-top-color:#38bdf8;border-radius:50%;animation:spin 1s linear infinite;"></div>
                </div>

                {{-- SVG chart --}}
                <div x-show="!chartLoading" style="padding:14px 16px;">
                    <svg id="chart-svg" width="428" height="240" viewBox="0 0 428 240" style="display:block;overflow:visible;"></svg>
                    <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:9px;color:#374151;">
                        <span>↑ sens du vent (direction de propagation)</span>
                        <span><span style="color:#22c55e;">■</span> axe favorable &nbsp;<span style="color:#ef4444;">■</span> hors axe</span>
                    </div>
                </div>

                {{-- Jour sélectionné --}}
                <div style="padding:8px 16px;border-top:1px solid rgba(55,65,81,.4);display:flex;justify-content:space-between;font-size:10px;color:#4b5563;">
                    <span x-text="'Journée : ' + (days[selectedDayIdx]?.label ?? '—')"></span>
                    <span x-text="(chartData?.days[days[selectedDayIdx]?.raw]?.length ?? 0) + ' créneaux analysés'"></span>
                </div>
            </div>

            {{-- Side panel (comparaison multi-modèles) --}}
            <div id="panel" style="position:absolute;top:0;right:0;height:100%;background:#111827;border-left:1px solid rgba(55,65,81,.5);z-index:500;display:flex;flex-direction:column;box-shadow:-8px 0 32px rgba(0,0,0,.5);">

                {{-- Header : titre, méta, sun window, tabs, sélecteur jour, conformité --}}
                <div class="panel-header">
                    <div class="panel-titlebar">
                        <div style="flex:1;min-width:0;">
                            <h2 x-text="site.name"></h2>
                            <div class="panel-meta">
                                <span x-text="(site.altitude??'?')+' m'"></span>
                                <span class="sep">·</span>
                                <span x-text="(typeof site.lat==='number'?site.lat.toFixed(2):'?')+'°N '+(typeof site.lng==='number'?site.lng.toFixed(2):'?')+'°E'"></span>
                                <span class="sep">·</span>
                                <span x-text="site.level??''"></span>
                            </div>
                        </div>
                        <button class="panel-close" @click="closePanel()">✕</button>
                    </div>

                    <div x-show="multimodelData?.sun_window" class="panel-sun">
                        <span class="ico">☀</span>
                        <span>Lever <span class="mono" x-text="multimodelData?.sun_window?.sunrise_display"></span></span>
                        <span class="sep">→</span>
                        <span>Coucher <span class="mono" x-text="multimodelData?.sun_window?.sunset_display"></span></span>
                    </div>

                    <div class="panel-tabs">
                        <button class="pg-tab" :class="panelTab==='today'?'active':''" @click="panelTab='today'">Aujourd'hui</button>
                        <button class="pg-tab" :class="panelTab==='fivedays'?'active':''" @click="panelTab='fivedays'">Vue 5 jours</button>
                    </div>

                    <div x-show="panelTab==='today'" class="panel-day-row">
                        <button class="pg-day-arrow" :disabled="panelDayIdx===0" @click="panelDayShift(-1)">‹</button>
                        <div class="panel-day-label" x-text="panelDay?.label ?? '—'"></div>
                        <button class="pg-day-arrow" :disabled="panelDayIdx>=days.length-1" @click="panelDayShift(1)">›</button>
                        <div class="panel-conformity" x-show="multimodelData">
                            <span>Conformité</span>
                            <div class="bar"><div class="fill" :style="`width:${multimodelData?.conformity_pct??0}%;background:${conformityColor}`"></div></div>
                            <span class="val" x-text="(multimodelData?.conformity_pct ?? '—')+'%'"></span>
                        </div>
                    </div>
                </div>

                {{-- Légende sticky des modèles (uniquement sur l'onglet Aujourd'hui) --}}
                <div x-show="panelTab==='today' && multimodelData" class="panel-legend">
                    <template x-for="m in (multimodelData?.models??[])" :key="m.id">
                        <span class="pg-chip" :title="m.provider">
                            <span class="pg-chip-dot" :style="`background:${m.color}`"></span>
                            <span x-text="m.name"></span>
                        </span>
                    </template>
                    <span class="pg-chip pg-chip-consensus">
                        <span class="pg-chip-dot"></span>
                        Consensus
                    </span>
                </div>

                {{-- Loader --}}
                <div x-show="multimodelLoading" class="panel-loader">
                    <div class="panel-spinner"></div>
                </div>

                {{-- Onglet "Aujourd'hui" : placeholder pour les 7 graphes (étape 3) --}}
                <div x-show="!multimodelLoading && panelTab==='today'" class="panel-scroll">
                    <div class="panel-placeholder">
                        <strong>Comparaison multi-modèles</strong><br>
                        Les graphes (vent min/moy/max, direction, précipitations,
                        humidité, température) seront affichés ici.<br><br>
                        <span style="font-size:11px;color:#374151;">
                            Données chargées :
                            <span x-text="(multimodelData?.models?.length ?? 0)+' modèles · '+(multimodelData?.hours?.length ?? 0)+' créneaux'"></span>
                        </span>
                    </div>
                </div>

                {{-- Onglet "Vue 5 jours" : placeholder --}}
                <div x-show="panelTab==='fivedays'" class="panel-scroll">
                    <div class="panel-placeholder">
                        <strong>Vue 5 jours</strong><br>
                        Bientôt disponible — comparaison des modèles<br>
                        sur l'horizon complet.
                    </div>
                </div>

                {{-- Footer --}}
                <div class="panel-footer">
                    <span>Open-Meteo · 10 modèles météo</span>
                    <span>Horizon 5 jours</span>
                </div>
            </div>
        </div>

        {{-- ═══ DROPDOWNS (position:fixed) ═══════════════ --}}
        <div x-show="dayDropOpen" class="dd-menu" :style="`top:${dayDropPos.top}px;left:${dayDropPos.left}px;min-width:260px;`" @click.stop>
            <template x-for="(day,idx) in days" :key="idx">
                <button class="dd-item" :class="selectedDayIdx===idx?'is-active':''" @click="selectDay(idx);dayDropOpen=false">
                    <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;" :style="{background:day.bestStatus==='green'?'#22c55e':day.bestStatus==='orange'?'#f59e0b':day.bestStatus==='red'?'#ef4444':'#6b7280'}"></span>
                    <span style="flex:1;font-weight:500;" x-text="day.label"></span>
                    <span x-show="day.greenSlots>0" style="color:#4ade80;font-size:11px;font-family:'DM Mono',monospace;" x-text="day.greenSlots+'h'"></span>
                    <span x-show="!day.greenSlots" style="color:#374151;font-size:11px;">—</span>
                    <span x-show="selectedDayIdx===idx" style="color:#4ade80;margin-left:4px;">✓</span>
                </button>
            </template>
        </div>
        <div x-show="bmDropOpen" class="dd-menu" :style="`top:${bmDropPos.top}px;right:${bmDropPos.right}px;width:230px;`" @click.stop>
            <template x-for="bm in basemapList" :key="bm.key">
                <button class="dd-item" :class="currentBasemap===bm.key?'is-active':''" @click="switchBasemap(bm.key);bmDropOpen=false">
                    <span x-text="bm.icon" style="font-size:16px;width:20px;text-align:center;flex-shrink:0;"></span>
                    <div style="flex:1;min-width:0;"><div style="font-weight:500;" x-text="bm.label"></div><div class="dd-sub" x-text="bm.desc"></div></div>
                    <span x-show="currentBasemap===bm.key" style="color:#4ade80;">✓</span>
                </button>
            </template>
        </div>

    </div>
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
        const NS='http://www.w3.org/2000/svg';

        // ── Génération du SVG du popup ───────────────────────────────
        function buildChartSVG(dayData, siteInfo) {
            const svgEl = document.getElementById('chart-svg');
            if (!svgEl || !dayData || !dayData.length) return;
            while (svgEl.firstChild) svgEl.removeChild(svgEl.firstChild);

            const N      = dayData.length;
            const W      = 428;
            const PITCH  = Math.floor((W - 20) / N);
            const COL_W  = PITCH - 2;
            const BASE_Y = 188;
            const WIND_H = 70; // px for max wind

            // Échelle vent dynamique
            const maxWind = Math.max(...dayData.map(d => d.wind_max || 0)) || 30;
            const scale   = WIND_H / maxWind;

            function mk(tag, attrs, parent) {
                const e = document.createElementNS(NS, tag);
                for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
                (parent || svgEl).appendChild(e);
                return e;
            }
            function txt(x, y, s, sz, fill, anchor) {
                const t = mk('text', {x, y, 'font-family':'DM Mono,monospace', 'font-size':sz, fill, ...(anchor?{'text-anchor':anchor}:{})});
                t.textContent = s;
            }

            // ── Labels section nuages ────────────────────────────────
            txt(10, 12, 'Couverture nuageuse', 9, '#9ca3af');
            txt(W,  12, '▲haute  ▬moy.  ▼basse', 8, '#4b5563', 'end');

            // ── Nuages (3 tuiles par heure) ──────────────────────────
            dayData.forEach((h, i) => {
                const x = 10 + i * PITCH;
                [[17, h.cloud_high], [29, h.cloud_mid], [41, h.cloud_low]].forEach(([ty, pct]) => {
                    mk('rect', {x, y:ty, width:COL_W, height:9, rx:2, fill:'#0d1b26'});
                    // Opacité inversée : ciel bleu = dégagé (100% → opaque), sombre = couvert (0% → transparent)
                    const op = pct != null ? ((100 - pct) / 100).toFixed(2) : '1.00';
                    mk('rect', {x, y:ty, width:COL_W, height:9, rx:2, fill:'#4b8db5', 'fill-opacity': op});
                });
            });
            // Labels heures nuages
            txt(10,  60, dayData[0]?.hour?.slice(0,2)+'h', 7, '#4b5563');
            const midI = Math.floor(N/2);
            txt(10 + midI*PITCH, 60, dayData[midI]?.hour?.slice(0,2)+'h', 7, '#4b5563', 'middle');
            txt(10 + (N-1)*PITCH + COL_W/2, 60, dayData[N-1]?.hour?.slice(0,2)+'h', 7, '#4b5563', 'end');

            // ── Séparateur ───────────────────────────────────────────
            mk('line', {x1:10, y1:67, x2:W-10, y2:67, stroke:'#1f2937', 'stroke-width':1});

            // ── Titre vent ───────────────────────────────────────────
            txt(10, 79, 'Vent km/h', 9, '#9ca3af');
            // Légende vent
            [[W-110,10,'#3b82f6','Min'],[W-70,10,'#22c55e','Moy'],[W-30,10,'#f97316','Max']].forEach(([lx,s,c,lb]) => {
                mk('rect',{x:lx-s,y:72,width:7,height:7,rx:1,fill:c});
                txt(lx-s+9, 79, lb, 8, '#6b7280');
            });

            // Ligne de base vent
            mk('line',{x1:10,y1:BASE_Y,x2:W-10,y2:BASE_Y,stroke:'#1f2937','stroke-width':1});

            // ── Barres vent ──────────────────────────────────────────
            const condMin = siteInfo?.wind_dir_min ?? 0;
            const condMax = siteInfo?.wind_dir_max ?? 360;

            function dirFav(dir) {
                if (dir == null) return false;
                if (condMin <= condMax) return dir >= condMin && dir <= condMax;
                return dir >= condMin || dir <= condMax; // chevauche Nord
            }

            // Échelle
            [0, Math.round(maxWind/2), Math.round(maxWind)].forEach(v => {
                const y = BASE_Y - v * scale;
                mk('line',{x1:7,y1:y,x2:9,y2:y,stroke:'#374151','stroke-width':1});
                txt(6, y+3, v, 7, '#374151', 'end');
            });

            dayData.forEach((h, i) => {
                const x   = 10 + i * PITCH;
                const fav = h.status === 'green' || h.status === 'orange';

                if (h.wind_max != null) {
                    const mxH = Math.max(1, h.wind_max * scale);
                    mk('rect',{x, y:BASE_Y-mxH, width:COL_W, height:mxH, rx:2,
                        fill: fav ? 'rgba(249,115,22,.2)' : 'rgba(75,85,99,.2)'});
                }
                if (h.wind_avg != null) {
                    const avH = Math.max(1, h.wind_avg * scale);
                    const aw  = Math.max(4, Math.floor(COL_W * 0.65));
                    const ax  = x + Math.floor((COL_W - aw) / 2);
                    mk('rect',{x:ax, y:BASE_Y-avH, width:aw, height:avH, rx:2,
                        fill: fav ? '#16a34a' : '#374151'});
                }
                if (h.wind_min != null) {
                    const mnH = Math.max(1, h.wind_min * scale);
                    const mw  = Math.max(2, Math.floor(COL_W * 0.35));
                    const mx2 = x + Math.floor((COL_W - mw) / 2);
                    mk('rect',{x:mx2, y:BASE_Y-mnH, width:mw, height:mnH, rx:2,
                        fill: fav ? '#3b82f6' : '#1e3a5f'});
                }

                // ── Flèche direction ──────────────────────────────
                if (h.wind_dir != null) {
                    const arrowColor = dirFav(h.wind_dir) ? '#22c55e' : '#ef4444';
                    const cx = x + COL_W/2;
                    const cy = BASE_Y + 14;
                    const g  = mk('g', {transform:`translate(${cx},${cy}) rotate(${(h.wind_dir + 180) % 360})`});
                    mk('polygon', {points:'0,-7 4,3 0,1 -4,3', fill:arrowColor}, g);
                }
            });

            // Labels heures vent
            txt(10, BASE_Y+30, dayData[0]?.hour?.slice(0,2)+'h', 7, '#4b5563');
            txt(10+midI*PITCH, BASE_Y+30, dayData[midI]?.hour?.slice(0,2)+'h', 7, '#4b5563', 'middle');
            txt(10+(N-1)*PITCH+COL_W/2, BASE_Y+30, dayData[N-1]?.hour?.slice(0,2)+'h', 7, '#4b5563', 'end');
        }

        // ── Alpine component ─────────────────────────────────────────
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
            _panelMapState:null, // sauvegarde center+zoom carte avant ouverture

            async init(){await this.$nextTick();this.initMap();await this.loadSites();},

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
                    const icon=L.divIcon({className:'',html:pgIcon(SC[st]),iconSize:[36,40],iconAnchor:[18,20]});
                    if(this.markers[site.id]){
                        const was=this.markers[site.id].getElement()?.querySelector('.pg-marker')?.classList.contains('selected');
                        this.markers[site.id].setIcon(icon);
                        if(was)setTimeout(()=>this.markers[site.id]?.getElement()?.querySelector('.pg-marker')?.classList.add('selected'),10);
                    }else{
                        const mk=L.marker([site.lat,site.lng],{icon}).addTo(this.map).bindTooltip(site.name,{permanent:false,direction:'top',offset:[0,-16]});
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
                const pw=460, ph=310;
                let left=r.right+12;
                if(left+pw>window.innerWidth-10) left=r.left-pw-12;
                if(left<10) left=10;
                let top=r.top-100;
                if(top<60) top=60;
                if(top+ph>window.innerHeight-10) top=window.innerHeight-ph-10;
                this.chartPos={top,left};

                // Sélectionner le marqueur visuellement
                Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-marker')?.classList.remove('selected'));
                markerEl?.querySelector('.pg-marker')?.classList.add('selected');

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
                this._restoreMapState();
                Object.values(this.markers).forEach(m=>m.getElement()?.querySelector('.pg-marker')?.classList.remove('selected'));
                this.site={};
            },

            // ── Side panel : navigation jour + chargement multi-modèles ──
            get panelDay(){return this.days[this.panelDayIdx]??null;},
            get conformityColor(){
                const c=this.multimodelData?.conformity_pct;
                if(c==null) return '#6b7280';
                if(c>=75) return '#22c55e';
                if(c>=50) return '#f59e0b';
                return '#ef4444';
            },
            panelDayShift(delta){
                const next=Math.max(0, Math.min(this.days.length-1, this.panelDayIdx+delta));
                if(next===this.panelDayIdx) return;
                this.panelDayIdx=next;
                this.loadMultimodel();
            },
            async loadMultimodel(){
                if(!this.site?.id) return;
                const day=this.days[this.panelDayIdx]?.raw;
                if(!day) return;
                const ymd=this._dayRawToYmd(day);
                this.multimodelData=null;
                this.multimodelLoading=true;
                try{
                    const r=await fetch(`/api/sites/${this.site.id}/multimodel?day=${ymd}`);
                    if(!r.ok) throw new Error('HTTP '+r.status);
                    this.multimodelData=await r.json();
                }catch(e){console.error('multimodel load failed',e);}
                this.multimodelLoading=false;
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
        };}
    </script>
@endpush
