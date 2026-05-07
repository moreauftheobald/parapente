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
                        <button class="pg-tab" :class="panelTab==='today'?'active':''" @click="setPanelTab('today')">Aujourd'hui</button>
                        <button class="pg-tab" :class="panelTab==='fivedays'?'active':''" @click="setPanelTab('fivedays')">Vue 5 jours</button>
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

                    <div x-show="panelTab==='fivedays'" class="panel-day-row">
                        <div class="panel-day-label" x-text="fiveDaysRangeLabel"></div>
                        <div class="panel-conformity" x-show="multimodel5Data">
                            <span>Conformité moy.</span>
                            <div class="bar"><div class="fill" :style="`width:${multimodel5Data?.conformity_pct??0}%;background:${conformity5Color}`"></div></div>
                            <span class="val" x-text="(multimodel5Data?.conformity_pct ?? '—')+'%'"></span>
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

                {{-- Onglet "Vue 5 jours" : loader pendant chargement --}}
                <div x-show="panelTab==='fivedays' && multimodel5Loading" class="panel-loader">
                    <div class="panel-spinner"></div>
                </div>

                {{-- Onglet "Vue 5 jours" : 6 graphes sur 5 jours × 24h --}}
                <div x-show="panelTab==='fivedays' && !multimodel5Loading && multimodel5Data" class="panel-scroll">
                    <template x-for="cfg in CHART_CONFIGS" :key="'5d-'+cfg.id">
                        <div class="chart-section">
                            <div class="chart-header" @click="toggleChart5(cfg.id)">
                                <span><span class="chart-title" x-text="cfg.title"></span><span class="chart-unit" x-text="cfg.unit"></span></span>
                                <span class="chart-toggle" :class="chartCollapsed5[cfg.id]?'collapsed':''">▾</span>
                            </div>
                            <div class="chart-svg-wrap" :class="chartCollapsed5[cfg.id]?'collapsed':''">
                                <svg :id="'svg5-'+cfg.id" class="chart-svg"></svg>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- État vide --}}
                <div x-show="panelTab==='fivedays' && !multimodel5Loading && !multimodel5Data" class="panel-placeholder">
                    Aucune donnée disponible.
                </div>

                {{-- Footer --}}
                <div class="panel-footer">
                    <span>Open-Meteo · 10 modèles météo</span>
                    <span>Horizon 5 jours</span>
                </div>
            </div>
