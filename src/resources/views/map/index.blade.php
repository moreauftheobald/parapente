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
        #chart-popup { position:fixed; z-index:2000; width:600px; background:#111827; border:1px solid rgba(255,255,255,.14); border-radius:16px; box-shadow:0 24px 64px rgba(0,0,0,.85); transition:opacity .2s; color:#fff; }
        #chart-popup .popup-meta { color:#e5e7eb; }
        #chart-popup .popup-foot { color:#cbd5e1; }

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
        .pg-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:rgba(31,41,55,.6); border:1px solid rgba(55,65,81,.5); font-size:12px; color:#e5e7eb; user-select:none; }
        .pg-chip-dot { width:9px; height:3px; border-radius:1px; flex-shrink:0; }
        .pg-chip-consensus { background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.18); color:#fff; font-weight:500; }
        .pg-chip-consensus .pg-chip-dot { background-image:repeating-linear-gradient(90deg,#fff 0 3px,transparent 3px 6px); height:2px; }

        .panel-loader { flex:1; display:flex; align-items:center; justify-content:center; }
        .panel-spinner { width:28px; height:28px; border:2px solid #374151; border-top-color:#38bdf8; border-radius:50%; animation:spin 1s linear infinite; }

        .panel-scroll { flex:1; overflow-y:auto; min-height:0; }
        .panel-placeholder { padding:60px 24px; text-align:center; font-size:13px; color:#4b5563; line-height:1.6; }
        .panel-placeholder strong { color:#9ca3af; font-weight:600; }

        .panel-footer { padding:10px 20px; border-top:1px solid rgba(55,65,81,.4); flex-shrink:0; display:flex; justify-content:space-between; font-size:10px; color:#374151; }

        /* Sections de graphes dans le panel */
        .chart-section { padding:0 18px; border-bottom:1px solid rgba(55,65,81,.25); }
        .chart-section:last-child { border-bottom:none; padding-bottom:14px; }
        .chart-header { display:flex; justify-content:space-between; align-items:baseline; padding:14px 0 6px; cursor:pointer; user-select:none; transition:opacity .15s; }
        .chart-header:hover { opacity:.85; }
        .chart-title { font-size:12px; color:#e5e7eb; font-weight:600; text-transform:uppercase; letter-spacing:.06em; }
        .chart-unit { font-size:11px; color:#cbd5e1; font-family:'DM Mono',monospace; margin-left:8px; }
        .chart-toggle { font-size:13px; color:#9ca3af; transition:transform .2s; line-height:1; }
        .chart-toggle.collapsed { transform:rotate(-90deg); }
        .chart-svg-wrap { overflow:hidden; transition:max-height .25s ease-out; max-height:200px; }
        .chart-svg-wrap.collapsed { max-height:0; }
        .chart-svg { display:block; width:100%; height:120px; overflow:visible; }

        /* Tooltip multi-modèles au survol des graphes */
        #chart-tooltip { position:fixed; z-index:9999; background:#0f172a; border:1px solid rgba(75,85,99,.7); border-radius:10px; padding:10px 12px; font-size:11px; color:#e5e7eb; pointer-events:none; box-shadow:0 12px 32px rgba(0,0,0,.7); min-width:200px; max-width:260px; }
        #chart-tooltip .tt-hour { font-size:13px; font-weight:600; color:#fff; margin-bottom:8px; font-family:'DM Mono',monospace; }
        #chart-tooltip .tt-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:2px 0; }
        #chart-tooltip .tt-row .name { display:flex; align-items:center; gap:6px; color:#cbd5e1; font-size:11px; }
        #chart-tooltip .tt-row .name .dot { width:9px; height:3px; border-radius:1px; flex-shrink:0; }
        #chart-tooltip .tt-row .val { color:#fff; font-family:'DM Mono',monospace; font-size:11px; white-space:nowrap; }
        #chart-tooltip .tt-row.consensus { padding-bottom:5px; margin-bottom:5px; border-bottom:1px solid rgba(55,65,81,.5); }
        #chart-tooltip .tt-row.consensus .name { color:#fff; font-weight:600; }
        #chart-tooltip .tt-row.consensus .name .dot { background-image:repeating-linear-gradient(90deg,#fff 0 3px,transparent 3px 6px); height:2px; }
        #chart-tooltip .tt-empty { color:#6b7280; font-style:italic; padding:2px 0; font-size:10px; }

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
                <div style="padding:18px 20px 14px;border-bottom:1px solid rgba(55,65,81,.4);display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div style="color:#fff;font-size:18px;font-weight:600;" x-text="chartSite?.name"></div>
                        <div class="popup-meta" style="font-size:13px;margin-top:4px;">
                            <span x-text="chartSite?.altitude+' m'"></span> ·
                            <span x-text="chartSite?.level"></span> ·
                            <span style="color:#fbbf24;font-size:18px;line-height:1;">☀</span>
                            <span x-text="chartSunWindow?.sunrise_display+' – '+chartSunWindow?.sunset_display"></span>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <button @click="openPanel(); chartOpen=false;"
                                style="padding:7px 16px;border-radius:10px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);color:#fff;font-size:14px;cursor:pointer;transition:all .15s;"
                                onmouseover="this.style.background='rgba(255,255,255,.16)'"
                                onmouseout="this.style.background='rgba(255,255,255,.08)'">
                            Détails ›
                        </button>
                        <button @click="chartOpen=false"
                                style="width:32px;height:32px;border-radius:50%;border:none;background:transparent;color:#cbd5e1;cursor:pointer;font-size:17px;"
                                onmouseover="this.style.background='rgba(55,65,81,.7)';this.style.color='#fff'"
                                onmouseout="this.style.background='transparent';this.style.color='#cbd5e1'">✕</button>
                    </div>
                </div>

                {{-- Loader --}}
                <div x-show="chartLoading" style="padding:50px;display:flex;align-items:center;justify-content:center;">
                    <div style="width:30px;height:30px;border:2px solid #374151;border-top-color:#38bdf8;border-radius:50%;animation:spin 1s linear infinite;"></div>
                </div>

                {{-- SVG chart (viewBox inchangé pour conserver la logique JS) --}}
                <div x-show="!chartLoading" style="padding:18px 20px;">
                    <svg id="chart-svg" width="558" height="312" viewBox="0 0 428 240" style="display:block;overflow:visible;"></svg>
                    <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:#cbd5e1;">
                        <span>↑ sens du vent (direction de propagation)</span>
                        <span><span style="color:#22c55e;">■</span> axe favorable &nbsp;<span style="color:#ef4444;">■</span> hors axe</span>
                    </div>
                </div>

                {{-- Jour sélectionné --}}
                <div class="popup-foot" style="padding:10px 20px;border-top:1px solid rgba(55,65,81,.4);display:flex;justify-content:space-between;font-size:13px;">
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

                {{-- Onglet "Aujourd'hui" : 6 graphes multi-modèles --}}
                <div x-show="!multimodelLoading && panelTab==='today' && multimodelData" class="panel-scroll">
                    <template x-for="cfg in CHART_CONFIGS" :key="cfg.id">
                        <div class="chart-section">
                            <div class="chart-header" @click="toggleChart(cfg.id)">
                                <span><span class="chart-title" x-text="cfg.title"></span><span class="chart-unit" x-text="cfg.unit"></span></span>
                                <span class="chart-toggle" :class="chartCollapsed[cfg.id]?'collapsed':''">▾</span>
                            </div>
                            <div class="chart-svg-wrap" :class="chartCollapsed[cfg.id]?'collapsed':''">
                                <svg :id="'svg-'+cfg.id" class="chart-svg"></svg>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- État vide si pas de données --}}
                <div x-show="!multimodelLoading && panelTab==='today' && !multimodelData" class="panel-placeholder">
                    Aucune donnée disponible pour ce jour.
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

        {{-- ═══ TOOLTIP MULTI-MODÈLES (position:fixed) ═══ --}}
        <div id="chart-tooltip" x-show="tooltip.visible" :style="`top:${tooltip.y}px;left:${tooltip.x}px;`">
            <div class="tt-hour" x-text="tooltip.hour"></div>
            <template x-if="tooltip.consensus !== null">
                <div class="tt-row consensus">
                    <span class="name"><span class="dot"></span>Consensus</span>
                    <span class="val" x-text="tooltip.consensus"></span>
                </div>
            </template>
            <template x-for="row in tooltip.rows" :key="row.id">
                <div class="tt-row">
                    <span class="name"><span class="dot" :style="`background:${row.color}`"></span><span x-text="row.name"></span></span>
                    <span class="val" x-text="row.value"></span>
                </div>
            </template>
            <div x-show="!tooltip.rows.length && tooltip.consensus===null" class="tt-empty">Aucune donnée</div>
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
            txt(10, 12, 'Couverture nuageuse', 10, '#e5e7eb');
            txt(W,  12, '▲haute  ▬moy.  ▼basse', 9, '#cbd5e1', 'end');

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
            // Labels heures nuages — toutes les 2h
            const midI = Math.floor(N/2);
            for (let i = 0; i < N; i += 2) {
                const x = 10 + i * PITCH + (i === N-1 ? COL_W/2 : 0);
                const anchor = i === 0 ? null : (i === N-1 ? 'end' : 'middle');
                txt(x, 60, dayData[i]?.hour?.slice(0,2)+'h', 9, '#cbd5e1', anchor);
            }

            // ── Séparateur ───────────────────────────────────────────
            mk('line', {x1:10, y1:67, x2:W-10, y2:67, stroke:'#1f2937', 'stroke-width':1});

            // ── Titre vent ───────────────────────────────────────────
            txt(10, 79, 'Vent km/h', 10, '#e5e7eb');
            // Légende vent
            [[W-110,10,'#3b82f6','Min'],[W-70,10,'#22c55e','Moy'],[W-30,10,'#f97316','Max']].forEach(([lx,s,c,lb]) => {
                mk('rect',{x:lx-s,y:72,width:7,height:7,rx:1,fill:c});
                txt(lx-s+9, 79, lb, 9, '#cbd5e1');
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
                mk('line',{x1:7,y1:y,x2:9,y2:y,stroke:'#6b7280','stroke-width':1});
                txt(6, y+3, v, 9, '#cbd5e1', 'end');
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

            // Labels heures vent — toutes les 2h
            for (let i = 0; i < N; i += 2) {
                const x = 10 + i * PITCH + (i === N-1 ? COL_W/2 : 0);
                const anchor = i === 0 ? null : (i === N-1 ? 'end' : 'middle');
                txt(x, BASE_Y+30, dayData[i]?.hour?.slice(0,2)+'h', 9, '#cbd5e1', anchor);
            }
        }

        // ── Configuration des 7 graphes du panel multi-modèles ──────
        const CHART_CONFIGS = [
            {id:'wind-avg', title:'Vent — Vitesse moyenne',   unit:'km/h',  type:'line', key:'wind_avg',    yMin:0,    yMax:'auto', favBand:'speed', consensusKey:'wind_speed', wrap:false},
            {id:'wind-max', title:'Vent — Rafales (max)',     unit:'km/h',  type:'line', key:'wind_max',    yMin:0,    yMax:'auto', favBand:null,    consensusKey:'wind_gust',  wrap:false},
            {id:'wind-dir', title:'Direction du vent',        unit:'',      type:'line', key:'wind_dir',    yMin:0,    yMax:360,    favBand:'dir',   consensusKey:'wind_dir',   wrap:true,  yTicks:'compass'},
            {id:'precip',   title:'Précipitations',           unit:'mm/h',  type:'bar',  key:'precip',      yMin:0,    yMax:'auto', favBand:null,    consensusKey:'precip',     wrap:false},
            {id:'humidity', title:'Humidité relative',        unit:'%',     type:'line', key:'humidity',    yMin:0,    yMax:100,    favBand:null,    consensusKey:null,         wrap:false},
            {id:'temp',     title:'Température',              unit:'°C',    type:'line', key:'temperature', yMin:'auto', yMax:'auto', favBand:null,  consensusKey:null,         wrap:false},
        ];

        // ── Helpers SVG ──────────────────────────────────────────────
        function svgMk(svg, tag, attrs, parent) {
            const e = document.createElementNS(NS, tag);
            for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
            (parent || svg).appendChild(e);
            return e;
        }

        function computeYDomain(cfg, data) {
            const vals = [];
            data.hours.forEach(h => {
                Object.values(data.data[h] || {}).forEach(m => {
                    const v = m[cfg.key];
                    if (v != null) vals.push(+v);
                });
                if (cfg.consensusKey) {
                    const cv = data.consensus[h]?.[cfg.consensusKey];
                    if (cv != null) vals.push(+cv);
                }
            });
            let yMin = cfg.yMin === 'auto' ? (vals.length ? Math.min(...vals) : 0) : cfg.yMin;
            let yMax = cfg.yMax === 'auto' ? (vals.length ? Math.max(...vals) : 1) : cfg.yMax;
            // Petite marge en haut/bas pour les axes auto
            if (cfg.yMin === 'auto') yMin = Math.floor(yMin - 1);
            if (cfg.yMax === 'auto') yMax = Math.ceil(yMax + 1);
            if (yMin === yMax) yMax = yMin + 1;
            return [yMin, yMax];
        }

        function formatTickValue(v) {
            if (Math.abs(v) >= 100) return Math.round(v).toString();
            if (Math.abs(v) >= 10)  return Math.round(v).toString();
            return (Math.round(v * 10) / 10).toString();
        }

        // Construit un path SVG depuis une liste de points (avec gestion des null
        // et du wrap pour la direction du vent : si saut > 180° on interrompt).
        function buildLinePath(points, wrap) {
            let d = '';
            let prev = null;
            for (const pt of points) {
                if (pt == null) { prev = null; continue; }
                if (prev == null || (wrap && Math.abs(pt.v - prev.v) > 180)) {
                    d += `M ${pt.x.toFixed(1)} ${pt.y.toFixed(1)} `;
                } else {
                    d += `L ${pt.x.toFixed(1)} ${pt.y.toFixed(1)} `;
                }
                prev = pt;
            }
            return d.trim();
        }

        // Bandeau jour/nuit + bandeau favorable (vitesse ou direction).
        function drawBackgroundBands(svg, cfg, data, geo) {
            const { PAD_L, PAD_T, innerW, innerH, xScale, yScale, hours, N } = geo;
            const [yMin, yMax] = geo.yDomain;
            const sunStart = data.sun_window?.start_hour;
            const sunEnd   = data.sun_window?.end_hour;

            // Bandeau "jour" (fenêtre solaire) — léger éclaircissement
            hours.forEach((h, i) => {
                const isDay = sunStart != null && h >= sunStart && h <= sunEnd;
                if (!isDay) return;
                const x1 = i === 0 ? PAD_L : (xScale(i-1) + xScale(i)) / 2;
                const x2 = i === N-1 ? PAD_L + innerW : (xScale(i) + xScale(i+1)) / 2;
                svgMk(svg, 'rect', {x:x1, y:PAD_T, width:Math.max(0,x2-x1), height:innerH, fill:'rgba(255,255,255,.06)'});
            });

            // Bandeau favorable
            if (cfg.favBand === 'speed') {
                const lo = data.site?.wind_speed_min, hi = data.site?.wind_speed_max;
                if (lo != null && hi != null) {
                    const yA = yScale(Math.min(hi, yMax));
                    const yB = yScale(Math.max(lo, yMin));
                    if (yB > yA) svgMk(svg, 'rect', {x:PAD_L, y:yA, width:innerW, height:yB-yA, fill:'rgba(34,197,94,.08)'});
                }
            } else if (cfg.favBand === 'dir') {
                const lo = data.site?.wind_dir_min, hi = data.site?.wind_dir_max;
                if (lo != null && hi != null) {
                    if (lo <= hi) {
                        const yA = yScale(hi), yB = yScale(lo);
                        svgMk(svg, 'rect', {x:PAD_L, y:yA, width:innerW, height:yB-yA, fill:'rgba(34,197,94,.08)'});
                    } else {
                        // Chevauche le Nord (ex: 340° → 30°) : 2 zones
                        svgMk(svg, 'rect', {x:PAD_L, y:yScale(360), width:innerW, height:yScale(lo)-yScale(360), fill:'rgba(34,197,94,.08)'});
                        svgMk(svg, 'rect', {x:PAD_L, y:yScale(hi),  width:innerW, height:yScale(0)-yScale(hi),   fill:'rgba(34,197,94,.08)'});
                    }
                }
            }
        }

        // Labels compas pour la direction du vent (provenance)
        const COMPASS_TICKS = [
            {deg:0,   label:'N'},
            {deg:90,  label:'E'},
            {deg:180, label:'S'},
            {deg:270, label:'O'},
            {deg:360, label:'N'},
        ];

        function drawAxes(svg, cfg, geo) {
            const { PAD_L, PAD_T, innerW, xScale, yScale, hours, N, H } = geo;
            const [yMin, yMax] = geo.yDomain;

            // Grille horizontale + labels Y
            let ticks;
            if (cfg.yTicks === 'compass') {
                // Direction du vent : 1 tick tous les 45°, label = point cardinal
                ticks = COMPASS_TICKS.map(c => ({value:c.deg, label:c.label}));
            } else {
                ticks = [yMin, (yMin + yMax) / 2, yMax].map(v => ({value:v, label:formatTickValue(v)}));
            }
            ticks.forEach(t => {
                const y = yScale(t.value);
                svgMk(svg, 'line', {x1:PAD_L, y1:y, x2:PAD_L+innerW, y2:y, stroke:'#1f2937', 'stroke-width':1, 'stroke-dasharray':'2,3'});
                const lbl = svgMk(svg, 'text', {x:PAD_L-5, y:y+3.5, 'text-anchor':'end', 'font-size':10, fill:'#cbd5e1', 'font-family':'DM Mono,monospace'});
                lbl.textContent = t.label;
            });

            // Labels heures — toutes les 2h (et toujours la première et la dernière)
            const labelIdx = [];
            for (let i = 0; i < N; i += 2) labelIdx.push(i);
            if (labelIdx[labelIdx.length - 1] !== N - 1) labelIdx.push(N - 1);
            labelIdx.forEach(idx => {
                if (idx < 0 || idx >= N) return;
                const x = xScale(idx);
                const anchor = idx === 0 ? 'start' : (idx === N - 1 ? 'end' : 'middle');
                const t = svgMk(svg, 'text', {x, y:H-5, 'text-anchor':anchor, 'font-size':10, fill:'#cbd5e1', 'font-family':'DM Mono,monospace'});
                t.textContent = String(hours[idx]).padStart(2,'0') + 'h';
            });
        }

        function makeGeometry(svg, cfg, data) {
            const W = svg.clientWidth || 600;
            const H = 120;
            const PAD_L = 32, PAD_R = 8, PAD_T = 8, PAD_B = 22;
            const innerW = W - PAD_L - PAD_R;
            const innerH = H - PAD_T - PAD_B;
            const hours = data.hours;
            const N = hours.length;
            const yDomain = computeYDomain(cfg, data);
            const xScale = i => N <= 1 ? PAD_L + innerW/2 : PAD_L + (i / (N-1)) * innerW;
            const yScale = v => PAD_T + innerH - ((v - yDomain[0]) / (yDomain[1] - yDomain[0])) * innerH;
            svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
            svg.setAttribute('preserveAspectRatio', 'none');
            return {W, H, PAD_L, PAD_R, PAD_T, PAD_B, innerW, innerH, hours, N, xScale, yScale, yDomain};
        }

        // ── Graphe linéaire multi-modèles ────────────────────────────
        function buildLineChart(svgId, cfg, data, app) {
            const svg = document.getElementById(svgId);
            if (!svg) return;
            while (svg.firstChild) svg.removeChild(svg.firstChild);

            const geo = makeGeometry(svg, cfg, data);
            drawBackgroundBands(svg, cfg, data, geo);
            drawAxes(svg, cfg, geo);

            // 1 ligne par modèle
            data.models.forEach(model => {
                const pts = data.hours.map((h, i) => {
                    const v = data.data[h]?.[model.id]?.[cfg.key];
                    return v == null ? null : {x: geo.xScale(i), y: geo.yScale(+v), v: +v};
                });
                const d = buildLinePath(pts, cfg.wrap);
                if (d) svgMk(svg, 'path', {d, fill:'none', stroke:model.color, 'stroke-width':1.2, 'stroke-opacity':.7, 'stroke-linecap':'round', 'stroke-linejoin':'round'});
            });

            // Ligne consensus (par-dessus, double trait noir épais + blanc pointillé)
            if (cfg.consensusKey) {
                const pts = data.hours.map((h, i) => {
                    const v = data.consensus[h]?.[cfg.consensusKey];
                    return v == null ? null : {x: geo.xScale(i), y: geo.yScale(+v), v: +v};
                });
                const d = buildLinePath(pts, cfg.wrap);
                if (d) {
                    svgMk(svg, 'path', {d, fill:'none', stroke:'#000', 'stroke-width':3.5, 'stroke-opacity':.5, 'stroke-linecap':'round', 'stroke-linejoin':'round'});
                    svgMk(svg, 'path', {d, fill:'none', stroke:'#fff', 'stroke-width':1.6, 'stroke-dasharray':'5,3', 'stroke-linecap':'round', 'stroke-linejoin':'round'});
                }
            }

            if (app) attachTooltipHandlers(svg, cfg, data, geo, app);
        }

        // ── Graphe en barres groupées (pour les précipitations) ──────
        // Pour chaque heure : N barres fines côte à côte (1 par modèle).
        // La convergence se lit visuellement à l'alignement des hauteurs.
        function buildBarChart(svgId, cfg, data, app) {
            const svg = document.getElementById(svgId);
            if (!svg) return;
            while (svg.firstChild) svg.removeChild(svg.firstChild);

            const geo = makeGeometry(svg, cfg, data);
            drawBackgroundBands(svg, cfg, data, geo);
            drawAxes(svg, cfg, geo);

            const { PAD_L, PAD_T, innerH, innerW, hours, N, yScale } = geo;
            const baseY = PAD_T + innerH;
            const hourW  = innerW / N;
            const groupW = hourW * 0.78;
            const barW   = Math.max(1.2, groupW / data.models.length);

            hours.forEach((h, i) => {
                const xCenter = PAD_L + i * hourW + hourW/2;
                const groupX  = xCenter - groupW/2;
                data.models.forEach((m, mi) => {
                    const v = data.data[h]?.[m.id]?.[cfg.key];
                    if (v == null || v <= 0) return;
                    const yTop = yScale(+v);
                    const x    = groupX + mi * (groupW / data.models.length);
                    svgMk(svg, 'rect', {x:x.toFixed(1), y:yTop.toFixed(1), width:barW.toFixed(1), height:Math.max(1, baseY - yTop).toFixed(1), fill:m.color, 'fill-opacity':.85, rx:0.5});
                });

                // Marqueur consensus au-dessus du groupe (petit triangle)
                const cv = data.consensus[h]?.[cfg.consensusKey];
                if (cv != null && cv > 0) {
                    const cy = yScale(+cv);
                    svgMk(svg, 'polygon', {points:`${xCenter-3.5},${cy-5} ${xCenter+3.5},${cy-5} ${xCenter},${cy-1}`, fill:'#fff', 'fill-opacity':.85});
                }
            });

            if (app) attachTooltipHandlers(svg, cfg, data, geo, app);
        }

        // ── Tooltip multi-modèles ────────────────────────────────────
        const COMPASS_8 = ['N','NE','E','SE','S','SO','O','NO'];
        function degToCompass(deg) {
            return COMPASS_8[Math.round(((deg % 360) + 360) % 360 / 45) % 8];
        }

        function formatTooltipValue(cfg, v) {
            if (v == null) return '—';
            switch (cfg.id) {
                case 'wind-dir':  return Math.round(v) + '° ' + degToCompass(v);
                case 'precip':    return v.toFixed(1) + ' mm/h';
                case 'temp':      return v.toFixed(1) + '°C';
                case 'humidity':  return Math.round(v) + '%';
                default:          return v.toFixed(1) + ' km/h';
            }
        }

        // Attache un overlay + un curseur vertical sur le SVG du graphe.
        // Met à jour app.tooltip au survol pour afficher les valeurs des
        // 10 modèles + le consensus à l'heure pointée.
        function attachTooltipHandlers(svg, cfg, data, geo, app) {
            const { PAD_L, PAD_T, PAD_B, innerW, innerH, hours, N, xScale, H, W } = geo;

            // Curseur vertical (initialement caché hors zone)
            const cursor = svgMk(svg, 'line', {
                x1: -10, y1: PAD_T, x2: -10, y2: PAD_T + innerH,
                stroke: 'rgba(255,255,255,.45)', 'stroke-width': 1, 'stroke-dasharray': '3,3',
                'pointer-events': 'none'
            });

            // Overlay invisible qui capte les events
            const overlay = svgMk(svg, 'rect', {
                x: PAD_L, y: PAD_T, width: innerW, height: innerH,
                fill: 'transparent', 'pointer-events': 'all', style: 'cursor:crosshair;'
            });

            const onMove = (e) => {
                const rect   = svg.getBoundingClientRect();
                // Le SVG utilise viewBox 0 0 W H ; on convertit clientX → unités viewBox
                const scaleX = rect.width  > 0 ? W / rect.width  : 1;
                const localX = (e.clientX - rect.left) * scaleX;

                // Trouver l'index d'heure le plus proche
                let bestIdx = 0, bestDist = Infinity;
                for (let i = 0; i < N; i++) {
                    const d = Math.abs(localX - xScale(i));
                    if (d < bestDist) { bestDist = d; bestIdx = i; }
                }
                const hour    = hours[bestIdx];
                const xCursor = xScale(bestIdx);
                cursor.setAttribute('x1', xCursor);
                cursor.setAttribute('x2', xCursor);

                // Construire les rows pour le tooltip (modèles avec valeur)
                const rows = data.models.map(m => {
                    const v = data.data[hour]?.[m.id]?.[cfg.key];
                    if (v == null) return null;
                    return { id: m.id, color: m.color, name: m.name, value: formatTooltipValue(cfg, +v) };
                }).filter(Boolean);

                let consensus = null;
                if (cfg.consensusKey) {
                    const cv = data.consensus[hour]?.[cfg.consensusKey];
                    if (cv != null) consensus = formatTooltipValue(cfg, +cv);
                }

                // Mise à jour Alpine
                app.tooltip.hour      = String(hour).padStart(2,'0') + 'h00';
                app.tooltip.consensus = consensus;
                app.tooltip.rows      = rows;
                app.tooltip.visible   = true;

                // Position : suit le curseur, évite les bords
                const tw = 240, th = Math.min(320, rows.length * 20 + 60);
                let tx = e.clientX + 14;
                let ty = e.clientY + 14;
                if (tx + tw > window.innerWidth)  tx = e.clientX - tw - 14;
                if (ty + th > window.innerHeight) ty = e.clientY - th - 14;
                app.tooltip.x = Math.max(8, tx);
                app.tooltip.y = Math.max(8, ty);
            };

            const onLeave = () => {
                cursor.setAttribute('x1', -10);
                cursor.setAttribute('x2', -10);
                app.tooltip.visible = false;
            };

            overlay.addEventListener('mousemove', onMove);
            overlay.addEventListener('mouseleave', onLeave);
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
            // État collapse de chaque section graphe
            chartCollapsed:{},
            // Tooltip flottant des graphes
            tooltip:{visible:false, x:0, y:0, hour:'', consensus:null, rows:[]},

            // Configuration des graphes exposée pour le template
            CHART_CONFIGS,

            async init(){
                await this.$nextTick();
                this.initMap();
                await this.loadSites();
                // Re-rendu des graphes du panel sur redimensionnement
                window.addEventListener('resize', () => {
                    if (this.panelOpen && this.multimodelData) this.renderCharts();
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
                const pw=600, ph=400;
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
                    const r=await fetch(`/api/sites/${this.site.id}/multimodel?day=${ymd}&period=24h`);
                    if(!r.ok) throw new Error('HTTP '+r.status);
                    this.multimodelData=await r.json();
                }catch(e){console.error('multimodel load failed',e);}
                this.multimodelLoading=false;
                this.$nextTick(()=>this.renderCharts());
            },
            renderCharts(){
                if(!this.multimodelData) return;
                CHART_CONFIGS.forEach(cfg => {
                    if (this.chartCollapsed[cfg.id]) return; // SVG caché : on ne re-rend pas
                    const fn = cfg.type === 'bar' ? buildBarChart : buildLineChart;
                    fn('svg-' + cfg.id, cfg, this.multimodelData, this);
                });
            },
            toggleChart(id){
                this.chartCollapsed[id] = !this.chartCollapsed[id];
                if (!this.chartCollapsed[id]) {
                    // Re-render quand on ré-ouvre (le SVG était caché → clientWidth = 0)
                    this.$nextTick(() => {
                        const cfg = CHART_CONFIGS.find(c => c.id === id);
                        if (!cfg || !this.multimodelData) return;
                        const fn = cfg.type === 'bar' ? buildBarChart : buildLineChart;
                        fn('svg-' + id, cfg, this.multimodelData, this);
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
        };}
    </script>
@endpush
