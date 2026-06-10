            {{-- ═══ VOLET DROIT : détail du site / de la balise sélectionné ═══ --}}
            {{-- Le wrapper et l'ouverture/fermeture sont fournis par le shell
                 global (via rightOpen). Ici on ne déclare que le contenu.
                 S'ouvre au clic sur un marqueur (openRightPanel). --}}
            <div id="right-panel">

                {{-- ═══════════ PLACEHOLDER (aucune sélection) ═══════════ --}}
                {{-- Affiché quand le volet est ouvert mais qu'aucun site/balise
                     n'a été sélectionné — typiquement quand l'utilisateur a
                     cliqué le bouton ? de la navbar sans avoir cliqué sur la
                     carte. Donne une instruction et un bouton fermer pour
                     ne pas laisser un panneau noir totalement vide. --}}
                <template x-if="!selectedFeature">
                    <div class="rp-wrap">
                        <div class="rp-head">
                            <div class="rp-headline">
                                <span class="rp-name">Détail d'un site</span>
                            </div>
                            <button class="rp-close" @click="rightOpen = false" title="Fermer">✕</button>
                        </div>
                        <div class="rp-placeholder" style="padding:48px 24px;text-align:center;">
                            Clique sur un site ou une balise de la carte pour afficher son détail ici.
                        </div>
                    </div>
                </template>

                {{-- ═══════════ SITE ═══════════ --}}
                {{-- Refonte panneau droit v2 (markup .rp2). Onglets :
                     Synthèse (implémenté) · Scoring (Phase 2) · Modèles (Phase 3).
                     Styles : map/_partials/styles/right-panel-v2.
                     Canvas : map/_partials/scripts/panel-synthese. --}}
                <template x-if="selectedFeature?.type === 'site'">
                    <div class="rp2">

                        {{-- En-tête : nom + métadonnées + score du jour --}}
                        <div class="site-hd">
                            <div class="site-hd-row">
                                <div style="min-width:0;">
                                    <div class="site-name" x-text="selectedFeature?.name ?? ''"></div>
                                    <div class="site-meta">
                                        <template x-if="selectedFeature?.altitude != null">
                                            <span class="smeta"><i class="ti ti-mountain" aria-hidden="true"></i><span x-text="selectedFeature.altitude + ' m'"></span></span>
                                        </template>
                                        <template x-if="siteOrientationLabel">
                                            <span class="smeta"><i class="ti ti-compass" aria-hidden="true"></i><span x-text="siteOrientationLabel"></span></span>
                                        </template>
                                        <template x-if="siteLevelLabel">
                                            <span class="smeta"><i class="ti ti-users" aria-hidden="true"></i><span x-text="siteLevelLabel"></span></span>
                                        </template>
                                        <template x-if="panelSunWindow">
                                            <span class="smeta"><i class="ti ti-sun" aria-hidden="true"></i><span x-text="(panelSunWindow.sunrise_display ?? '—') + '–' + (panelSunWindow.sunset_display ?? '—')"></span></span>
                                        </template>
                                    </div>
                                </div>
                                <div style="display:flex;align-items:flex-start;gap:10px;flex-shrink:0;">
                                    <div style="text-align:right;">
                                        <div class="hero-num" :style="`color:${synthHero.text}`" x-text="synthScore ?? '—'"></div>
                                        <div class="hero-lbl">score du jour</div>
                                    </div>
                                    <button class="rp-close" @click="closeRightPanel()" title="Fermer">✕</button>
                                </div>
                            </div>
                        </div>

                        {{-- Bandeau « Scoring perso » (inchangé) --}}
                        <div class="rp-user-banner" x-show="authUser && selectedFeature?.user_scoring === 'active'" x-cloak
                             title="Le scoring affiché ici utilise tes conditions personnelles. Tu peux le modifier depuis ton profil.">
                            <span class="rp-user-banner-ico">★</span>
                            <span>Scoring perso · <span class="rp-user-banner-name" x-text="authUser?.display_name ?? authUser?.name ?? ''"></span></span>
                            <a href="{{ route('user.scorings') }}" class="rp-user-banner-link">Gérer mes scorings ↗</a>
                        </div>

                        {{-- Onglets principaux --}}
                        <div class="main-tabs">
                            <div class="mtab" :class="rpTab === 'synth' ? 'on' : ''" @click="setRpTab('synth')"><i class="ti ti-layout-dashboard" aria-hidden="true"></i>Synthèse</div>
                            <div class="mtab" :class="rpTab === 'score' ? 'on' : ''" @click="setRpTab('score')"><i class="ti ti-calendar-week" aria-hidden="true"></i>Scoring</div>
                            <div class="mtab" :class="rpTab === 'mod' ? 'on' : ''" @click="setRpTab('mod')"><i class="ti ti-chart-sankey" aria-hidden="true"></i>Modèles</div>
                        </div>

                        {{-- ══ VUE SYNTHÈSE ══ --}}
                        <div class="view" :class="rpTab === 'synth' ? 'on' : ''">
                            <div x-show="chartLoading" class="rp-loader"><div class="rp-spinner"></div></div>
                            <template x-if="!chartLoading && chartHasData">
                                <div>
                                    {{-- Hero strip --}}
                                    <div class="hero-strip" :style="`background:${synthHero.bg};border-color:${synthHero.border}`">
                                        <div class="hero-dot" :style="`background:${synthHero.dot}`"><i class="ti" :class="synthHero.icon" aria-hidden="true"></i></div>
                                        <div style="min-width:0;">
                                            <div class="hverdict" :style="`color:${synthHero.text}`" x-text="synthHero.label"></div>
                                            <div class="hwin" x-text="'Fenêtre optimale : ' + synthWindowLabel"></div>
                                        </div>
                                        <div class="hbig" :style="`color:${synthHero.text}`" x-text="synthScore ?? '—'"></div>
                                    </div>

                                    {{-- Nuages --}}
                                    <div class="block">
                                        <div class="blbl"><span><i class="ti ti-cloud" aria-hidden="true"></i> Couverture nuageuse</span><span style="color:rgba(255,255,255,0.16)">bas · moy · haut</span></div>
                                        <div class="cloud-wrap" id="rp2-cloud-wrap"></div>
                                        <div class="cax" id="rp2-cloud-ax"></div>
                                    </div>

                                    {{-- Vent double-axe --}}
                                    <div class="block">
                                        <div class="blbl"><span><i class="ti ti-wind" aria-hidden="true"></i> Vent · direction · altitude</span></div>
                                        <div class="chart-outer" id="rp2-chart-outer">
                                            <canvas id="rp2-wind-cv" role="img" aria-label="Graphique vent moyen, rafales, plafond et décollage avec tooltip interactif"></canvas>
                                            <div class="hover-line" id="rp2-hover-line"></div>
                                            <div class="tt" id="rp2-wind-tt">
                                                <div class="tt-h" id="rp2-tt-hour">—</div>
                                                <div class="tt-r"><span class="tt-l"><i class="ti ti-wind" aria-hidden="true"></i>Vent moy.</span><span class="tt-v" id="rp2-tt-mean">—</span></div>
                                                <div class="tt-r"><span class="tt-l"><i class="ti ti-bolt" aria-hidden="true"></i>Rafales</span><span class="tt-v" id="rp2-tt-gust" style="color:#fbbf24">—</span></div>
                                                <div class="tt-r"><span class="tt-l"><i class="ti ti-compass" aria-hidden="true"></i>Direction</span><span class="tt-v" id="rp2-tt-dir">—</span></div>
                                                <div class="tt-sep"></div>
                                                <div class="tt-r"><span class="tt-l"><i class="ti ti-arrow-bar-up" aria-hidden="true"></i>Plafond</span><span class="tt-v" id="rp2-tt-ceil" style="color:#60a5fa">—</span></div>
                                                <div class="tt-r"><span class="tt-l"><i class="ti ti-map-pin" aria-hidden="true"></i>Décollage</span><span class="tt-v" id="rp2-tt-deco">—</span></div>
                                                <div class="tt-r"><span class="tt-l"><i class="ti ti-ruler" aria-hidden="true"></i>Marge vol</span><span class="tt-v" id="rp2-tt-margin">—</span></div>
                                            </div>
                                        </div>
                                        <div class="dir-strip" id="rp2-dir-strip"></div>
                                        <div class="axis-strip" id="rp2-axis-strip"></div>
                                        <div class="wax" id="rp2-wax"></div>
                                        <div class="wind-leg">
                                            <div class="wleg"><div class="wleg-b" style="background:rgba(74,222,128,0.85)"></div>Moy.</div>
                                            <div class="wleg"><div class="wleg-b" style="background:rgba(251,191,36,0.6)"></div>Rafales</div>
                                            <div class="wleg"><div class="wleg-l" style="background:#60a5fa"></div>Plafond</div>
                                            <div class="wleg"><div class="wleg-d"></div>Décollage</div>
                                            <div class="wleg"><div class="wleg-b" style="background:rgba(74,222,128,0.28)"></div>Axe</div>
                                            <div class="wleg"><div class="wleg-b" style="background:rgba(239,68,68,0.25)"></div>Hors axe</div>
                                        </div>
                                    </div>

                                    {{-- Mini rose (vers la rose animée complète plus tard) --}}
                                    <div class="mini-rose" style="cursor:default;">
                                        <svg width="44" height="44" viewBox="0 0 44 44" aria-label="Mini rose des vents">
                                            <circle cx="22" cy="22" r="18" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="1"/>
                                            <circle cx="22" cy="22" r="11" fill="none" stroke="rgba(255,255,255,0.04)" stroke-width="1"/>
                                            <line x1="22" y1="4" x2="22" y2="40" stroke="rgba(255,255,255,0.05)" stroke-width="0.5"/>
                                            <line x1="4" y1="22" x2="40" y2="22" stroke="rgba(255,255,255,0.05)" stroke-width="0.5"/>
                                            <template x-if="synthMiniRose && synthMiniRose.dir != null">
                                                <g :transform="`rotate(${(synthMiniRose.dir + 180) % 360} 22 22)`">
                                                    <line x1="22" y1="22" x2="22" y2="8" :stroke="synthMiniRose.inAxis ? '#4ade80' : '#f87171'" stroke-width="2" stroke-linecap="round"/>
                                                    <polygon points="22,4 19,10 25,10" :fill="synthMiniRose.inAxis ? '#4ade80' : '#f87171'"/>
                                                </g>
                                            </template>
                                            <circle cx="22" cy="22" r="3" :fill="synthMiniRose && synthMiniRose.inAxis ? '#4ade80' : '#f87171'"/>
                                            <text x="22" y="3" text-anchor="middle" font-size="7" fill="rgba(255,255,255,0.3)">N</text>
                                        </svg>
                                        <div style="flex:1;min-width:0;">
                                            <div class="mr-lbl" x-text="'Direction — ' + (synthMiniRose?.hour ?? '—')"></div>
                                            <div class="mr-row">
                                                <div>
                                                    <div class="mrv" :style="`color:${synthMiniRose && synthMiniRose.inAxis ? '#4ade80' : '#f87171'}`">
                                                        <span x-text="synthMiniRose?.dir != null ? Math.round(synthMiniRose.dir) + '°' : '—'"></span>
                                                        <span class="mrvu" x-text="synthMiniRose?.dirCompass ?? ''"></span>
                                                    </div>
                                                    <div class="mrsub" x-text="synthMiniRose && synthMiniRose.inAxis ? 'Dans l\'axe' : 'Hors axe'"></div>
                                                </div>
                                                <div>
                                                    <div class="mrv" style="color:#4ade80"><span x-text="synthMiniRose?.avg != null ? Math.round(synthMiniRose.avg) : '—'"></span> <span class="mrvu">km/h</span></div>
                                                    <div class="mrsub">Moy.</div>
                                                </div>
                                                <div>
                                                    <div class="mrv" style="color:#fbbf24"><span x-text="synthMiniRose?.gust != null ? Math.round(synthMiniRose.gust) : '—'"></span> <span class="mrvu">km/h</span></div>
                                                    <div class="mrsub">Rafales</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Certitude consensus --}}
                                    <div class="cert">
                                        <div class="cert-row"><span class="cert-lbl">Certitude consensus</span><span class="cert-val" x-text="(chartConfidence ?? '—') + '%'"></span></div>
                                        <div class="cert-bg"><div class="cert-fill" :style="`width:${chartConfidence ?? 0}%`"></div></div>
                                        <div class="cert-sub" x-text="synthModels ? (synthModels.conv + ' modèles sur ' + synthModels.total + ' convergent') : 'Convergence indisponible'"></div>
                                    </div>
                                </div>
                            </template>
                            <template x-if="!chartLoading && !chartHasData">
                                <div class="rp-placeholder" style="padding:32px 16px;text-align:center;">Aucune donnée disponible pour ce jour.</div>
                            </template>
                        </div>

                        {{-- ══ VUE SCORING ══ --}}
                        {{-- Grilles construites en JS (canvas style B) :
                             buildScoringTab → #rp2-sc-vola (réel) + #rp2-sc-qual (stub). --}}
                        <div class="view" :class="rpTab === 'score' ? 'on' : ''">
                            <div class="sc-ctrl">
                                <div class="tog">
                                    <div class="togtab" :class="scZoom === '1j' ? 'on' : ''" @click="setScZoom('1j')"><i class="ti ti-zoom-in" aria-hidden="true"></i>1j</div>
                                    <div class="togtab" :class="scZoom === '5j' ? 'on' : ''" @click="setScZoom('5j')"><i class="ti ti-zoom-out" aria-hidden="true"></i>5j</div>
                                </div>
                                <div class="dnav" :style="scZoom === '5j' ? 'opacity:0.32' : ''">
                                    <div class="dnavb" :class="(scZoom === '5j' || selectedDayIdx === 0) ? 'off' : ''" @click="scoringDayStep(-1)" aria-label="Précédent"><i class="ti ti-chevron-left" aria-hidden="true"></i></div>
                                    <div class="dlbl" x-text="scZoom === '1j' ? (days[selectedDayIdx]?.label ?? '') : fiveDaysRangeLabel"></div>
                                    <div class="dnavb" :class="(scZoom === '5j' || selectedDayIdx >= 4) ? 'off' : ''" @click="scoringDayStep(1)" aria-label="Suivant"><i class="ti ti-chevron-right" aria-hidden="true"></i></div>
                                </div>
                                <div class="tog" x-show="scoringHasPerso" x-cloak>
                                    <div class="togtab" :class="scMode === 'global' ? 'on' : ''" @click="setScMode('global')">Global</div>
                                    <div class="togtab" :class="scMode === 'perso' ? 'on-p' : ''" @click="setScMode('perso')"><i class="ti ti-user" aria-hidden="true"></i><span x-text="scoringPilotName"></span></div>
                                </div>
                            </div>
                            <div id="rp2-day-strip"></div>
                            <div class="sc-grid" id="rp2-sc-vola"></div>
                            <div class="sc-div"></div>
                            <div class="sc-grid sc-stub" id="rp2-sc-qual"></div>
                            <div class="sc-leg">
                                <div class="sleg"><div class="sleg-dot" style="background:#16a34a"></div>Favorable</div>
                                <div class="sleg"><div class="sleg-dot" style="background:#d97706"></div>Incertain</div>
                                <div class="sleg"><div class="sleg-dot" style="background:#dc2626"></div>Défavorable</div>
                                <div class="sleg" x-show="scMode === 'perso' && scoringHasPerso" x-cloak style="color:#a78bfa"><i class="ti ti-user" aria-hidden="true" style="font-size:9px"></i><span x-text="'Scoring ' + scoringPilotName"></span></div>
                            </div>
                        </div>

                        {{-- ══ VUE MODÈLES ══ --}}
                        {{-- Ribbon Chart.js + divergence + chips construits en JS
                             (renderModelsTab). Tabs variables / pill : Alpine. --}}
                        <div class="view" :class="rpTab === 'mod' ? 'on' : ''">
                            <div class="mod-vtabs" id="rp2-mod-vtabs">
                                <template x-for="v in MOD_VARS" :key="v.key">
                                    <div class="vtab" :class="modVar === v.key ? 'on' : ''" @click="setModVar(v.key)"><i class="ti" :class="v.icon" aria-hidden="true"></i><span x-text="v.label"></span></div>
                                </template>
                            </div>
                            <div class="mod-sub">
                                <div class="tog">
                                    <div class="togtab" :class="modZoom === '1j' ? 'on' : ''" @click="setModZoom('1j')">1j</div>
                                    <div class="togtab" :class="modZoom === '5j' ? 'on' : ''" @click="setModZoom('5j')">5j</div>
                                </div>
                                <div class="dnav" :style="modZoom === '5j' ? 'opacity:0.32' : ''">
                                    <div class="dnavb" :class="(modZoom === '5j' || selectedDayIdx === 0) ? 'off' : ''" @click="modelsDayStep(-1)" aria-label="Précédent"><i class="ti ti-chevron-left" aria-hidden="true"></i></div>
                                    <div class="dlbl" x-text="modZoom === '1j' ? (days[selectedDayIdx]?.label ?? '') : fiveDaysRangeLabel"></div>
                                    <div class="dnavb" :class="(modZoom === '5j' || selectedDayIdx >= 4) ? 'off' : ''" @click="modelsDayStep(1)" aria-label="Suivant"><i class="ti ti-chevron-right" aria-hidden="true"></i></div>
                                </div>
                                <div class="conform-pill"><i class="ti ti-check" aria-hidden="true"></i><span x-text="(modelsPayload?.conformity_pct ?? '—') + '%'"></span></div>
                            </div>
                            <div x-show="modelsLoading" class="rp-loader"><div class="rp-spinner"></div></div>
                            <template x-if="!modelsLoading && modelsPayload">
                                <div>
                                    <div class="mod-chart-area">
                                        <div class="mch-hdr">
                                            <span class="mch-lbl"><i class="ti" :class="modVarObj.icon" aria-hidden="true"></i><span x-text="modVarObj.label"></span></span>
                                            <span class="mch-unit" x-text="modVarObj.unit"></span>
                                        </div>
                                        <div class="ribbon-wrap"><canvas id="rp2-ribbon-cv" role="img" aria-label="Consensus ribbon multi-modèles"></canvas></div>
                                    </div>
                                    <div class="div-bar" id="rp2-mod-divbar"></div>
                                    <div class="mod-tax" id="rp2-mod-tax"></div>
                                    <div class="mod-leg">
                                        <div class="mleg"><div class="mleg-line"></div>Modèles</div>
                                        <div class="mleg"><div class="mleg-dash"></div>Consensus</div>
                                        <div class="mleg"><div class="mleg-band"></div>Enveloppe</div>
                                    </div>
                                    <div class="mod-grid" id="rp2-mod-grid"></div>
                                </div>
                            </template>
                            <template x-if="!modelsLoading && !modelsPayload">
                                <div class="rp-placeholder" style="padding:32px 16px;text-align:center;">Aucune donnée modèles pour ce jour.</div>
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
                                <span class="rp-meta"><span class="rp-dot">·</span><span>maj <span x-text="baliseFreshness"></span></span></span>
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
                                                        <span x-text="baliseTrendArrow" :style="`font-size:14px;color:${baliseTrendColor};`"></span>
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

                {{-- ═══════════ STATION MÉTÉO ═══════════ --}}
                <template x-if="selectedFeature?.type === 'station'">
                    <div class="rp-wrap">

                        <div class="rp-head">
                            <div class="rp-headline">
                                <span class="rp-name" x-text="selectedFeature?.name ?? ''"></span>
                                <template x-if="stationNetworkLabel">
                                    <span class="rp-meta"><span class="rp-dot">·</span><span x-text="stationNetworkLabel"></span></span>
                                </template>
                                <template x-if="selectedFeature?.altitude_m != null">
                                    <span class="rp-meta"><span class="rp-dot">·</span><span class="mono" x-text="selectedFeature.altitude_m + ' m'"></span></span>
                                </template>
                                <span class="rp-meta"><span class="rp-dot">·</span><span>maj <span x-text="stationFreshness"></span></span></span>
                            </div>
                            <button class="rp-close" @click="closeRightPanel()" title="Fermer">✕</button>
                        </div>

                        <div class="rp-tabs">
                            <button class="rp-tab active">Relevés météo</button>
                        </div>

                        <div class="rp-pane rp-pane-scroll">
                            <div x-show="stationLoading" class="rp-loader"><div class="rp-spinner"></div></div>

                            <template x-if="!stationLoading && stationData">
                                <div>
                                    <template x-if="!stationData.readings || !stationData.readings.length">
                                        <div style="padding:24px 18px;color:#9ca3af;font-size:13px;">Aucun relevé du jour pour cette station.</div>
                                    </template>

                                    {{-- Section 1 : Dernier relevé --}}
                                    <template x-if="stationData.latest">
                                        <div class="rp-station-now">
                                            <div class="rp-station-now-header">
                                                <span class="rp-station-freshness-dot" :class="'rp-station-fresh-' + stationFreshnessClass"></span>
                                                <span x-text="'Mis à jour ' + stationFreshness"></span>
                                            </div>

                                            <div class="rp-station-metrics-main">
                                                <div class="rp-station-metric rp-station-metric-dir">
                                                    <svg width="52" height="52" viewBox="-22 -22 44 44" style="overflow:visible;">
                                                        <circle r="20" fill="none" stroke="#374151" stroke-width="1.2"/>
                                                        <g x-show="stationData.latest.wind_direction !== null"
                                                           :transform="`rotate(${((stationData.latest.wind_direction ?? 0) + 180) % 360})`">
                                                            <polygon points="0,-15 6,6 0,1 -6,6" fill="#38bdf8"/>
                                                        </g>
                                                    </svg>
                                                    <span class="rp-station-metric-val" x-text="stationData.latest.wind_direction !== null ? (Math.round(stationData.latest.wind_direction) + '° ' + degToCompass(stationData.latest.wind_direction)) : '—'"></span>
                                                    <span class="rp-station-metric-lbl">Direction</span>
                                                </div>
                                                <div class="rp-station-metric">
                                                    <span class="rp-station-metric-big" x-text="stationData.latest.wind_speed_avg !== null ? stationData.latest.wind_speed_avg.toFixed(1) : '—'"></span>
                                                    <span class="rp-station-metric-unit">km/h</span>
                                                    <span class="rp-station-metric-lbl">Vent moyen</span>
                                                </div>
                                                <div class="rp-station-metric">
                                                    <span class="rp-station-metric-big rp-station-gust" x-text="stationData.latest.wind_speed_max !== null ? stationData.latest.wind_speed_max.toFixed(1) : '—'"></span>
                                                    <span class="rp-station-metric-unit">km/h</span>
                                                    <span class="rp-station-metric-lbl">Rafales</span>
                                                </div>
                                            </div>

                                            <div class="rp-station-metrics-sec">
                                                <template x-if="stationData.latest.temperature !== null">
                                                    <div class="rp-station-pill"><span class="rp-station-pill-lbl">Temp.</span><span class="rp-station-pill-val" x-text="stationData.latest.temperature.toFixed(1) + '°C'"></span></div>
                                                </template>
                                                <template x-if="stationData.latest.humidity !== null">
                                                    <div class="rp-station-pill"><span class="rp-station-pill-lbl">Hum.</span><span class="rp-station-pill-val" x-text="stationData.latest.humidity + '%'"></span></div>
                                                </template>
                                                <template x-if="stationData.latest.pressure_hpa !== null">
                                                    <div class="rp-station-pill"><span class="rp-station-pill-lbl">Pression</span><span class="rp-station-pill-val" x-text="stationData.latest.pressure_hpa.toFixed(1) + ' hPa'"></span></div>
                                                </template>
                                                <template x-if="stationData.latest.precipitation_mm !== null">
                                                    <div class="rp-station-pill"><span class="rp-station-pill-lbl">Précip.</span><span class="rp-station-pill-val" x-text="stationData.latest.precipitation_mm.toFixed(1) + ' mm'"></span></div>
                                                </template>
                                            </div>
                                            <div class="rp-station-metrics-sec" style="margin-top:0;">
                                                <template x-if="stationData.latest.dew_point !== null">
                                                    <div class="rp-station-pill"><span class="rp-station-pill-lbl">Pt rosée</span><span class="rp-station-pill-val" x-text="stationData.latest.dew_point.toFixed(1) + '°C'"></span></div>
                                                </template>
                                                <template x-if="stationData.latest.visibility_m !== null">
                                                    <div class="rp-station-pill"><span class="rp-station-pill-lbl">Visib.</span><span class="rp-station-pill-val" x-text="(stationData.latest.visibility_m >= 1000 ? (stationData.latest.visibility_m / 1000).toFixed(1) + ' km' : stationData.latest.visibility_m + ' m')"></span></div>
                                                </template>
                                                <template x-if="stationData.latest.cloud_cover_pct !== null">
                                                    <div class="rp-station-pill"><span class="rp-station-pill-lbl">Nébulosité</span><span class="rp-station-pill-val" x-text="stationData.latest.cloud_cover_pct + '%'"></span></div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    {{-- Section 2 : Graphiques SVG --}}
                                    <div x-show="stationData.readings && stationData.readings.length > 1">
                                        <div style="padding:8px 18px 4px;">
                                            <div style="font-size:13px;color:#e5e7eb;margin-bottom:4px;"
                                                 x-text="stationData.fallback ? 'Vent — dernières 24 h' : 'Vent — relevés du jour'"></div>
                                            <svg id="station-wind-svg" width="640" height="200" viewBox="0 0 440 140"
                                                 style="display:block;width:100%;height:auto;overflow:visible;"></svg>
                                            <div style="display:flex;gap:16px;font-size:11px;color:#9ca3af;margin-top:4px;padding:0 2px;">
                                                <span><span style="color:#f97316;">▮</span> rafales</span>
                                                <span><span style="color:#22c55e;">▬</span> moyen</span>
                                            </div>
                                        </div>

                                        <template x-if="stationHasTemp">
                                            <div style="padding:8px 18px 4px;">
                                                <div style="font-size:13px;color:#e5e7eb;margin-bottom:4px;">Température &amp; Humidité</div>
                                                <svg id="station-temp-svg" width="640" height="160" viewBox="0 0 440 110"
                                                     style="display:block;width:100%;height:auto;overflow:visible;"></svg>
                                                <div style="display:flex;gap:16px;font-size:11px;color:#9ca3af;margin-top:4px;padding:0 2px;">
                                                    <span><span style="color:#f97316;">—</span> température (°C)</span>
                                                    <span><span style="color:#60a5fa;">┄</span> humidité (%)</span>
                                                </div>
                                            </div>
                                        </template>

                                        <template x-if="stationHasPressure">
                                            <div style="padding:8px 18px 4px;">
                                                <div style="font-size:13px;color:#e5e7eb;margin-bottom:4px;">Pression</div>
                                                <svg id="station-pressure-svg" width="640" height="120" viewBox="0 0 440 85"
                                                     style="display:block;width:100%;height:auto;overflow:visible;"></svg>
                                            </div>
                                        </template>
                                    </div>

                                    {{-- Section 3 : Tableau dépliable --}}
                                    <div x-show="stationData.readings && stationData.readings.length" style="padding:8px 18px 0;">
                                        <button @click="stationTableOpen = !stationTableOpen"
                                                style="background:none;border:none;color:#cbd5e1;cursor:pointer;font-size:13px;padding:6px 0;width:100%;text-align:left;display:flex;align-items:center;gap:6px;">
                                            <span :class="stationTableOpen ? '' : 'collapsed'" style="transition:transform .2s;display:inline-block;"
                                                  :style="stationTableOpen ? '' : 'transform:rotate(-90deg)'">▾</span>
                                            <span x-text="'Tableau horaire (' + stationData.readings.length + ' relevés)'"></span>
                                        </button>
                                        <div x-show="stationTableOpen" x-cloak class="rp-station-tablewrap">
                                            <table class="rp-station-table">
                                                <thead>
                                                    <tr>
                                                        <th>Heure</th><th>Dir</th><th>Moy</th><th>Raf</th><th>T°C</th><th>Hum</th><th>hPa</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="r in [...stationData.readings].reverse().slice(0, 50)" :key="r.observed_at">
                                                        <tr>
                                                            <td x-text="r.time" style="color:#e5e7eb;"></td>
                                                            <td><span x-text="r.wind_direction !== null ? r.wind_direction + '°' : '—'"></span></td>
                                                            <td x-text="r.wind_speed_avg !== null ? r.wind_speed_avg.toFixed(1) : '—'"></td>
                                                            <td x-text="r.wind_speed_max !== null ? r.wind_speed_max.toFixed(1) : '—'"></td>
                                                            <td x-text="r.temperature !== null ? r.temperature.toFixed(1) : '—'"></td>
                                                            <td x-text="r.humidity !== null ? r.humidity + '%' : '—'"></td>
                                                            <td x-text="r.pressure_hpa !== null ? r.pressure_hpa.toFixed(0) : '—'"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <div style="padding:10px 18px;border-top:1px solid rgba(55,65,81,.4);font-size:12px;color:#9ca3af;margin-top:8px;">
                                        <span x-text="(stationData.readings?.length ?? 0) + (stationData.fallback ? ' relevé(s) sur 24 h' : ' relevé(s) aujourd\'hui')"></span>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!stationLoading && !stationData">
                                <div class="rp-placeholder">Données indisponibles pour cette station.</div>
                            </template>
                        </div>

                    </div>
                </template>


            </div>
