            {{-- ═══ VOLET DROIT : détail du site / de la balise sélectionné ═══ --}}
            {{-- S'ouvre (moitié d'écran) au clic sur un marqueur. --}}
            <div id="right-panel" :class="rightPanelOpen ? 'open' : ''">

                {{-- ═══════════ SITE ═══════════ --}}
                <template x-if="selectedFeature?.type === 'site'">
                    <div class="rp-wrap">

                        {{-- Titre sur une seule ligne : Nom · Niveau · ☀ Lever … → Coucher … --}}
                        <div class="rp-head">
                            <div class="rp-headline">
                                <span class="rp-name" x-text="selectedFeature?.name ?? ''"></span>
                                <template x-if="siteLevelLabel">
                                    <span class="rp-meta"><span class="rp-dot">·</span><span x-text="siteLevelLabel"></span></span>
                                </template>
                                <template x-if="siteOrientationLabel">
                                    <span class="rp-meta"><span class="rp-dot">·</span><span>Orient. <span x-text="siteOrientationLabel"></span></span></span>
                                </template>
                                <template x-if="panelSunWindow">
                                    <span class="rp-meta">
                                        <span class="rp-dot">·</span>
                                        <span class="rp-sun-ico">☀</span>
                                        <span>Lever <span class="mono" x-text="panelSunWindow.sunrise_display ?? '—'"></span></span>
                                        <span class="rp-arrow">→</span>
                                        <span>Coucher <span class="mono" x-text="panelSunWindow.sunset_display ?? '—'"></span></span>
                                    </span>
                                </template>
                            </div>
                            <button class="rp-close" @click="closeRightPanel()" title="Fermer">✕</button>
                        </div>

                        {{-- Onglets --}}
                        <div class="rp-tabs">
                            <button class="rp-tab" :class="rpTab === 'synthese' ? 'active' : ''" @click="setRpTab('synthese')"
                                    x-text="'Synthèse · ' + (days[selectedDayIdx]?.label ?? '')"></button>
                            <button class="rp-tab" :class="rpTab === 'models' ? 'active' : ''" @click="setRpTab('models')"
                                    x-text="'Modèles météo · ' + (days[selectedDayIdx]?.label ?? '')"></button>
                            <button class="rp-tab" :class="rpTab === 'models5' ? 'active' : ''" @click="setRpTab('models5')">Modèles · 5 jours</button>
                        </div>

                        {{-- ── Onglet « Synthèse pour la journée » (ancienne popup site) ── --}}
                        <div class="rp-pane" x-show="rpTab === 'synthese'">
                            <div x-show="chartLoading" class="rp-loader"><div class="rp-spinner"></div></div>
                            <div x-show="!chartLoading">
                                <template x-if="chartHasData">
                                    <div>
                                        <div class="rp-confidence" x-show="chartConfidence !== null">
                                            <div class="panel-conformity">
                                                <span>Certitude de la prévision</span>
                                                <div class="bar"><div class="fill" :style="`width:${chartConfidence ?? 0}%;background:${chartConfidenceColor}`"></div></div>
                                                <span class="val" x-text="(chartConfidence ?? '—') + '%'"></span>
                                            </div>
                                        </div>
                                        <div class="rp-chart-box">
                                            <svg id="chart-svg" width="558" height="261" viewBox="0 0 428 200"
                                                 style="display:block;width:100%;height:auto;overflow:visible;"></svg>
                                            <div class="rp-chart-legend">
                                                <span>↑ sens du vent (direction de propagation)</span>
                                                <span><span style="color:#22c55e;">■</span> axe favorable&nbsp;&nbsp;<span style="color:#ef4444;">■</span> hors axe</span>
                                            </div>
                                        </div>
                                        <div class="rp-chart-box" style="margin-top:12px;">
                                            <svg id="ceiling-svg" viewBox="0 0 428 112"
                                                 style="display:block;width:100%;height:auto;overflow:visible;"></svg>
                                            <div class="rp-chart-legend">
                                                <span>plafond en altitude absolue (m) — Min / Moy / Max des modèles</span>
                                                <span><span style="color:#fbbf24;">┄</span> altitude du décollage&nbsp;&nbsp;<span style="color:#ef4444;">■</span> Moy sous le décollage</span>
                                            </div>
                                        </div>
                                        <div class="rp-chart-foot">
                                            <span x-text="'Journée : ' + (days[selectedDayIdx]?.label ?? '—')"></span>
                                            <span x-text="chartDayCount + ' créneaux analysés'"></span>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!chartHasData">
                                    <div class="rp-placeholder">Aucune donnée disponible pour ce jour.</div>
                                </template>
                            </div>
                        </div>

                        {{-- ── Onglet « Modèles météo du jour » ── --}}
                        <div class="rp-pane rp-pane-scroll" x-show="rpTab === 'models'">
                            <div x-show="multimodelLoading" class="rp-loader"><div class="rp-spinner"></div></div>
                            <template x-if="!multimodelLoading && multimodelData">
                                <div>
                                    <div class="rp-summary">
                                        <div class="panel-conformity">
                                            <span>Conformité</span>
                                            <div class="bar"><div class="fill" :style="`width:${multimodelData?.conformity_pct ?? 0}%;background:${conformityColor}`"></div></div>
                                            <span class="val" x-text="(multimodelData?.conformity_pct ?? '—') + '%'"></span>
                                        </div>
                                    </div>
                                    <div class="panel-legend">
                                        <template x-for="m in (multimodelData?.models ?? [])" :key="m.id">
                                            <span class="pg-chip" :title="m.provider"><span class="pg-chip-dot" :style="`background:${m.color}`"></span><span x-text="m.name"></span></span>
                                        </template>
                                        <span class="pg-chip pg-chip-consensus"><span class="pg-chip-dot"></span>Consensus</span>
                                    </div>
                                    <template x-for="cfg in CHART_CONFIGS" :key="cfg.id">
                                        <div class="chart-section">
                                            <div class="chart-header" @click="toggleChart(cfg.id)">
                                                <span><span class="chart-title" x-text="cfg.title"></span><span class="chart-unit" x-text="cfg.unit"></span></span>
                                                <span class="chart-toggle" :class="chartCollapsed[cfg.id] ? 'collapsed' : ''">▾</span>
                                            </div>
                                            <div class="chart-svg-wrap" :class="chartCollapsed[cfg.id] ? 'collapsed' : ''">
                                                <svg :id="'svg-' + cfg.id" class="chart-svg"></svg>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!multimodelLoading && !multimodelData">
                                <div class="rp-placeholder">Aucune donnée disponible pour ce jour.</div>
                            </template>
                        </div>

                        {{-- ── Onglet « Modèles météo sur 5 jours » ── --}}
                        <div class="rp-pane rp-pane-scroll" x-show="rpTab === 'models5'">
                            <div x-show="multimodel5Loading" class="rp-loader"><div class="rp-spinner"></div></div>
                            <template x-if="!multimodel5Loading && multimodel5Data">
                                <div>
                                    <div class="rp-summary">
                                        <span class="rp-range" x-text="fiveDaysRangeLabel"></span>
                                        <div class="panel-conformity">
                                            <span>Conformité moy.</span>
                                            <div class="bar"><div class="fill" :style="`width:${multimodel5Data?.conformity_pct ?? 0}%;background:${conformity5Color}`"></div></div>
                                            <span class="val" x-text="(multimodel5Data?.conformity_pct ?? '—') + '%'"></span>
                                        </div>
                                    </div>
                                    <div class="panel-legend">
                                        <template x-for="m in (multimodel5Data?.models ?? [])" :key="m.id">
                                            <span class="pg-chip" :title="m.provider"><span class="pg-chip-dot" :style="`background:${m.color}`"></span><span x-text="m.name"></span></span>
                                        </template>
                                        <span class="pg-chip pg-chip-consensus"><span class="pg-chip-dot"></span>Consensus</span>
                                    </div>
                                    <template x-for="cfg in CHART_CONFIGS" :key="'5d-' + cfg.id">
                                        <div class="chart-section">
                                            <div class="chart-header" @click="toggleChart5(cfg.id)">
                                                <span><span class="chart-title" x-text="cfg.title"></span><span class="chart-unit" x-text="cfg.unit"></span></span>
                                                <span class="chart-toggle" :class="chartCollapsed5[cfg.id] ? 'collapsed' : ''">▾</span>
                                            </div>
                                            <div class="chart-svg-wrap" :class="chartCollapsed5[cfg.id] ? 'collapsed' : ''">
                                                <svg :id="'svg5-' + cfg.id" class="chart-svg"></svg>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="!multimodel5Loading && !multimodel5Data">
                                <div class="rp-placeholder">Aucune donnée disponible.</div>
                            </template>
                        </div>

                    </div>
                </template>

                {{-- ═══════════ BALISE (design détaillé à venir) ═══════════ --}}
                <template x-if="selectedFeature?.type === 'balise'">
                    <div class="rp-wrap">
                        <div class="rp-head">
                            <div class="rp-headline">
                                <span class="rp-kind">Balise météo</span>
                                <span class="rp-name" x-text="selectedFeature?.name ?? ''"></span>
                            </div>
                            <button class="rp-close" @click="closeRightPanel()" title="Fermer">✕</button>
                        </div>
                        <div class="rp-pane"><div class="rp-placeholder">Contenu détaillé à définir ultérieurement.</div></div>
                    </div>
                </template>

            </div>
