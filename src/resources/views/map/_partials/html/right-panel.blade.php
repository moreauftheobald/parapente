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

                        {{-- Bandeau « Scoring perso » : visible quand l'utilisateur a un
                             scoring perso ACTIF sur ce site. On utilise x-show (et pas
                             <template x-if>) car les templates Alpine imbriqués dans
                             un autre <template x-if> peuvent avoir des soucis de
                             réactivité sur des propriétés ajoutées au runtime. --}}
                        <div class="rp-user-banner" x-show="authUser && selectedFeature?.user_scoring === 'active'" x-cloak
                             title="Le scoring affiché ici utilise tes conditions personnelles. Tu peux le modifier depuis ton profil.">
                            <span class="rp-user-banner-ico">★</span>
                            <span>Scoring perso · <span class="rp-user-banner-name" x-text="authUser?.display_name ?? authUser?.name ?? ''"></span></span>
                            <a href="{{ route('user.scorings') }}" class="rp-user-banner-link">Gérer mes scorings ↗</a>
                        </div>


                        {{-- Onglets --}}
                        <div class="rp-tabs">
                            <button class="rp-tab" :class="rpTab === 'synthese' ? 'active' : ''" @click="setRpTab('synthese')"
                                    x-text="'Synthèse · ' + (days[selectedDayIdx]?.label ?? '')"></button>
                            <button class="rp-tab" :class="rpTab === 'voting' ? 'active' : ''" @click="setRpTab('voting')">Détail scoring · 5 jours</button>
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

                        {{-- ── Onglet « Détail du scoring (voting logic) · 5 jours » ── --}}
                        {{-- Tableau par jour : 5 paramètres + ligne statut global,    --}}
                        {{-- une colonne par heure de la fenêtre solaire, case colorée --}}
                        {{-- selon le résultat de la voting logic.                     --}}
                        <div class="rp-pane rp-pane-scroll" x-show="rpTab === 'voting'">
                            <template x-if="!votingHasData">
                                <div class="rp-placeholder">Aucune donnée de scoring disponible.</div>
                            </template>
                            <template x-if="votingHasData">
                                <div class="rp-voting">
                                    <template x-for="day in votingDays" :key="day.raw">
                                        <div class="rp-voting-day">
                                            <div class="rp-voting-daylabel">
                                                <span x-text="day.label"></span>
                                                <span class="rp-voting-dayhint" x-text="day.hint"></span>
                                            </div>
                                            <div class="rp-voting-tablewrap">
                                                <table class="rp-voting-table">
                                                    <thead>
                                                        <tr>
                                                            <th class="rp-voting-th-param">Paramètre</th>
                                                            <template x-for="h in day.hours" :key="h">
                                                                <th class="rp-voting-th-hour" x-text="h + 'h'"></th>
                                                            </template>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <template x-for="row in day.rows" :key="row.key">
                                                            <tr :class="row.key === 'status' ? 'rp-voting-status' : ''">
                                                                <td class="rp-voting-td-param" x-text="row.label"></td>
                                                                <template x-for="(cell, idx) in row.cells" :key="idx">
                                                                    <td class="rp-voting-cell">
                                                                        {{-- Cas standard : pastille unie. Cas split (perso ≠ global) :
                                                                             carré découpé en diagonale, triangle haut-gauche = global,
                                                                             triangle bas-droite = perso. Tooltip = double titre. --}}
                                                                        <template x-if="!cell.colorGlobal">
                                                                            <span class="rp-voting-dot" :class="'rp-voting-' + (cell.color || 'na')"
                                                                                  @mouseenter="showVotingTip($event, cell.title)"
                                                                                  @mouseleave="hideVotingTip()"></span>
                                                                        </template>
                                                                        <template x-if="cell.colorGlobal">
                                                                            <span class="rp-voting-dot rp-voting-split"
                                                                                  :style="`background:
                                                                                    linear-gradient(135deg, var(--rp-v-${cell.colorGlobal}) 0%, var(--rp-v-${cell.colorGlobal}) 46%,
                                                                                    var(--rp-v-sep) 46%, var(--rp-v-sep) 54%,
                                                                                    var(--rp-v-${cell.color}) 54%, var(--rp-v-${cell.color}) 100%)`"
                                                                                  @mouseenter="showVotingTip($event, cell.titleGlobal + '\n' + cell.title)"
                                                                                  @mouseleave="hideVotingTip()"></span>
                                                                        </template>
                                                                    </td>
                                                                </template>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </template>
                                    <div class="rp-voting-legend">
                                        <span><span class="rp-voting-dot rp-voting-green"></span>OK</span>
                                        <span><span class="rp-voting-dot rp-voting-orange"></span>Prudence</span>
                                        <span><span class="rp-voting-dot rp-voting-red"></span>Éliminatoire</span>
                                        <span><span class="rp-voting-dot rp-voting-na"></span>N/A</span>
                                        <span x-show="authUser && selectedFeature?.user_scoring === 'active'" x-cloak>
                                            <span class="rp-voting-dot rp-voting-split"
                                                  style="background:linear-gradient(135deg,#f59e0b 0%,#f59e0b 46%,#fff 46%,#fff 54%,#22c55e 54%,#22c55e 100%);"></span>
                                            Global / perso (haut-gauche = standard, bas-droite = perso)
                                        </span>
                                    </div>
                                </div>
                            </template>
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

                {{-- Tooltip flottant des cellules du « Détail scoring » (cellules normales + cellules split) --}}
                <div class="rp-voting-tip" x-show="votingTip.visible" x-cloak
                     :style="`left:${votingTip.x}px; top:${votingTip.y}px;`">
                    <template x-for="(line, i) in votingTip.lines" :key="i">
                        <div x-text="line"></div>
                    </template>
                </div>

                {{-- ═══════════ BALISE ═══════════ --}}
                <template x-if="selectedFeature?.type === 'balise'">
                    <div class="rp-wrap">

                        {{-- Titre sur une seule ligne : Nom · Réseau · maj … --}}
                        <div class="rp-head">
                            <div class="rp-headline">
                                <span class="rp-name" x-text="selectedFeature?.name ?? ''"></span>
                                <template x-if="baliseReseauLabel">
                                    <span class="rp-meta"><span class="rp-dot">·</span><span x-text="baliseReseauLabel"></span></span>
                                </template>
                                <template x-if="baliseData?.balise?.altitude_m">
                                    <span class="rp-meta"><span class="rp-dot">·</span><span class="mono" x-text="baliseData.balise.altitude_m + ' m'"></span></span>
                                </template>
                                <span class="rp-meta"><span class="rp-dot">·</span><span>maj <span x-text="baliseFreshness()"></span></span></span>
                            </div>
                            <button class="rp-close" @click="closeRightPanel()" title="Fermer">✕</button>
                        </div>

                        {{-- Onglets (un seul pour l'instant) --}}
                        <div class="rp-tabs">
                            <button class="rp-tab active">Relevés météo</button>
                        </div>

                        {{-- ── Onglet « Relevés météo » (ancienne popup balise) ── --}}
                        <div class="rp-pane rp-pane-scroll">
                            <div x-show="baliseLoading" class="rp-loader"><div class="rp-spinner"></div></div>

                            <template x-if="!baliseLoading && baliseData">
                                <div>
                                    {{-- Pas de relevé du jour : message simple --}}
                                    <template x-if="!baliseData.readings || !baliseData.readings.length">
                                        <div style="padding:24px 18px;color:#9ca3af;font-size:13px;">Aucun relevé du jour pour cette balise.</div>
                                    </template>

                                    {{-- 3 colonnes : dernier relevé · rose des vents · légende --}}
                                    <div x-show="baliseData.readings && baliseData.readings.length" class="rp-balise-rose">

                                        {{-- Col 1 : dernier relevé (synthétique) --}}
                                        <div class="rp-balise-now">
                                            <template x-if="baliseData.latest">
                                                <div style="display:flex;flex-direction:column;align-items:center;">
                                                    <svg width="62" height="62" viewBox="-22 -22 44 44" style="overflow:visible;">
                                                        <circle r="20" fill="none" stroke="#374151" stroke-width="1.4"/>
                                                        <g x-show="baliseData.latest.wind_direction !== null"
                                                           :transform="`rotate(${((baliseData.latest.wind_direction ?? 0) + 180) % 360})`">
                                                            <polygon points="0,-15 7,7 0,2 -7,7" fill="#38bdf8"/>
                                                        </g>
                                                    </svg>
                                                    <span style="font-family:'DM Mono',monospace;font-size:11px;color:#cbd5e1;margin-top:2px;"
                                                          x-text="baliseData.latest.wind_direction !== null ? (Math.round(baliseData.latest.wind_direction) + '° ' + degToCompass(baliseData.latest.wind_direction)) : '—'"></span>
                                                    <div style="margin-top:12px;display:flex;align-items:baseline;gap:5px;">
                                                        <span style="font-size:26px;font-weight:600;color:#fff;font-family:'DM Mono',monospace;line-height:1;"
                                                              x-text="baliseData.latest.wind_speed_avg !== null ? baliseData.latest.wind_speed_avg.toFixed(1) : '—'"></span>
                                                        <span style="font-size:11px;color:#9ca3af;">km/h</span>
                                                        <span x-text="baliseTrendArrow()" :style="`font-size:14px;color:${baliseTrendColor()};`"></span>
                                                    </div>
                                                    <div style="font-family:'DM Mono',monospace;font-size:11px;color:#9ca3af;margin-top:2px;">
                                                        rafales <span x-text="baliseData.latest.wind_speed_max !== null ? Math.round(baliseData.latest.wind_speed_max) : '—'"></span>
                                                        · min <span x-text="baliseData.latest.wind_speed_min !== null ? Math.round(baliseData.latest.wind_speed_min) : '—'"></span>
                                                    </div>
                                                    <div style="font-family:'DM Mono',monospace;font-size:11px;color:#cbd5e1;margin-top:10px;line-height:1.6;text-align:center;">
                                                        <template x-if="baliseData.latest.temperature !== null">
                                                            <div><span style="color:#9ca3af;">temp.</span> <span x-text="baliseData.latest.temperature.toFixed(1) + '°C'"></span></div>
                                                        </template>
                                                        <template x-if="baliseData.latest.humidity !== null">
                                                            <div><span style="color:#9ca3af;">hum.</span> <span x-text="baliseData.latest.humidity + '%'"></span></div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                            <template x-if="!baliseData.latest">
                                                <div style="color:#6b7280;font-size:12px;text-align:center;">pas de relevé récent</div>
                                            </template>
                                        </div>

                                        {{-- Col 2 : rose des vents heure par heure --}}
                                        <div class="rp-balise-dial">
                                            <svg id="balise-rose-svg" viewBox="0 0 320 320" style="display:block;overflow:visible;"></svg>
                                        </div>

                                        {{-- Col 3 : légende --}}
                                        <div class="rp-balise-leg">
                                            <div style="color:#e5e7eb;font-size:13px;margin-bottom:4px;">Direction du vent — heure par heure</div>
                                            <div>Angle = direction d'où vient le vent</div>
                                            <div>Rayon = vitesse moyenne (km/h)</div>
                                            <div style="margin-top:8px;display:flex;align-items:center;gap:6px;">
                                                <span>matin</span>
                                                <span style="display:inline-block;width:60px;height:8px;border-radius:4px;background:linear-gradient(90deg,#38bdf8,#f59e0b);"></span>
                                                <span>soir</span>
                                            </div>
                                            <div style="margin-top:3px;">● blanc = dernier relevé</div>
                                            <div style="margin-top:8px;">
                                                <span style="color:#f97316;">▮</span> rafales&nbsp;&nbsp;<span style="color:#22c55e;">▬</span> moyen&nbsp;&nbsp;<span style="color:#3b82f6;">▬</span> min
                                            </div>
                                            <div style="margin-top:3px;color:#6b7280;">Survolez le graphe ↓ pour pointer une heure</div>
                                        </div>
                                    </div>

                                    {{-- Graphe vitesse du jour --}}
                                    <div x-show="baliseData.readings && baliseData.readings.length" style="padding:4px 18px 8px;">
                                        <div style="font-size:13px;color:#e5e7eb;margin-bottom:4px;"
                                             x-text="baliseData.fallback ? 'Vitesse — dernières 24 h' : 'Vitesse — relevés du jour'"></div>
                                        <svg id="balise-chart-svg" width="640" height="200" viewBox="0 0 440 140"
                                             style="display:block;width:100%;height:auto;overflow:visible;"></svg>
                                    </div>

                                    {{-- Pied --}}
                                    <div style="padding:10px 18px;border-top:1px solid rgba(55,65,81,.4);font-size:12px;color:#9ca3af;">
                                        <span x-text="(baliseData.readings?.length ?? 0) + (baliseData.fallback ? ' relevé(s) sur 24 h' : ' relevé(s) aujourd\'hui')"></span>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!baliseLoading && !baliseData">
                                <div class="rp-placeholder">Données indisponibles pour cette balise.</div>
                            </template>
                        </div>

                    </div>
                </template>


            </div>
