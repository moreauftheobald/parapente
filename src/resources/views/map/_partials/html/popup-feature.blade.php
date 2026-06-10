{{-- ═══ Popup flottante balise / station (refonte v2) ═══ --}}
{{-- Ancrée au marqueur (position calculée en JS : positionFeaturePopup).
     Cohabite avec le drawer site. Rose pilotée en JS (freq-rose) ;
     stats/header en bindings Alpine. Onglet Évolution : Phase 2/3. --}}
<div id="feature-popup" class="fpop" x-show="featurePopup.open" x-cloak
     @click.stop>

    {{-- HEADER --}}
    <div class="hd">
        <div class="hd-row">
            <div style="min-width:0;">
                <div class="hd-name" x-text="fpName"></div>
                <div class="hd-meta">
                    <template x-if="fpAltitude != null">
                        <span class="hm"><i class="ti ti-map-pin" aria-hidden="true"></i><span x-text="fpAltitude + ' m'"></span></span>
                    </template>
                    <template x-if="fpNetworkLabel">
                        <span class="hm"><i class="ti ti-antenna" aria-hidden="true"></i><span x-text="fpNetworkLabel"></span></span>
                    </template>
                </div>
            </div>
            <div class="hd-right">
                <div class="hd-actions">
                    <span class="live-dot" x-show="fpIsLive" x-cloak>Live</span>
                    <button class="fpop-close" @click="closeFeaturePopup()" title="Fermer" aria-label="Fermer">✕</button>
                </div>
                <span class="ts" x-text="fpFreshness"></span>
            </div>
        </div>
    </div>

    {{-- TABS --}}
    <div class="main-tabs">
        <div class="mtab" :class="featurePopup.tab === 'rose' ? 'on' : ''" @click="setFpTab('rose')"><i class="ti ti-wind" aria-hidden="true"></i>Rose des vents</div>
        <template x-if="featurePopup.type === 'station'">
            <div class="mtab" :class="featurePopup.tab === 'meas' ? 'on' : ''" @click="setFpTab('meas')"><i class="ti ti-dashboard" aria-hidden="true"></i>Mesures</div>
        </template>
        <div class="mtab" :class="featurePopup.tab === 'evo' ? 'on' : ''" @click="setFpTab('evo')"><i class="ti ti-chart-line" aria-hidden="true"></i>Évolution</div>
    </div>

    {{-- ══ VUE ROSE ══ --}}
    <div class="view" :class="featurePopup.tab === 'rose' ? 'on' : ''">
        <div x-show="fpLoading" class="fp-loader"><div class="fp-spinner"></div></div>
        <template x-if="!fpLoading">
            <div class="rose-view">
                <div class="period-row">
                    <button class="pbtn" :class="fpWindow === 3 ? 'on' : ''" @click="setFpWindow(3)">3h</button>
                    <button class="pbtn" :class="fpWindow === 6 ? 'on' : ''" @click="setFpWindow(6)">6h</button>
                    <button class="pbtn" :class="fpWindow === 12 ? 'on' : ''" @click="setFpWindow(12)">12h</button>
                    <button class="pbtn" :class="fpWindow === 24 ? 'on' : ''" @click="setFpWindow(24)">24h</button>
                    <button class="pbtn" :class="fpWindow === 72 ? 'on' : ''" @click="setFpWindow(72)">3j</button>
                </div>
                <div class="rose-body">
                    <div class="rose-cv-wrap">
                        <canvas id="fpop-rose-cv" width="200" height="200" style="display:block" role="img" aria-label="Rose des vents fréquentielle"></canvas>
                    </div>
                    <div class="stats-col">
                        <div class="stat">
                            <div class="slbl"><i class="ti ti-compass" aria-hidden="true"></i>Direction actuelle</div>
                            <div><span class="sval" style="color:#4ea8e0" x-text="fpCurrent.dir != null ? Math.round(fpCurrent.dir) + '°' : '—'"></span><span class="sunit" x-text="fpCurrent.dir != null ? ' ' + degToCompass(fpCurrent.dir) : ''"></span></div>
                        </div>
                        <div class="stat">
                            <div class="slbl"><i class="ti ti-wind" aria-hidden="true"></i>Vent moyen</div>
                            <div><span class="sval" :style="`color:${_spdColor(fpCurrent.mean)}`" x-text="fpCurrent.mean != null ? Math.round(fpCurrent.mean) : '—'"></span><span class="sunit">km/h</span></div>
                            <div class="sbar"><div class="sbar-f" :style="`width:${Math.min(100, Math.round((fpCurrent.mean ?? 0)/50*100))}%;background:${_spdColor(fpCurrent.mean)}`"></div></div>
                        </div>
                        <div class="stat">
                            <div class="slbl"><i class="ti ti-bolt" aria-hidden="true"></i>Rafales max</div>
                            <div><span class="sval" style="color:#fbbf24" x-text="fpCurrent.gust != null ? Math.round(fpCurrent.gust) : '—'"></span><span class="sunit">km/h</span></div>
                            <div class="sbar"><div class="sbar-f" :style="`width:${Math.min(100, Math.round((fpCurrent.gust ?? 0)/50*100))}%;background:#fbbf24`"></div></div>
                        </div>
                        <div class="stat">
                            <div class="slbl"><i class="ti ti-clock" aria-hidden="true"></i>Dir. dominante</div>
                            <div><span class="sval" style="color:#e8f4fd" x-text="fpRoseStats ? fpRoseStats.domDir + '°' : '—'"></span><span class="sunit" x-text="fpRoseStats ? ' ' + fpRoseStats.domCard : ''"></span></div>
                            <div class="ssub" x-text="fpRoseStats ? fpRoseStats.domPct + '% des relevés' : '—'"></div>
                        </div>
                    </div>
                </div>
                <div class="rose-legend">
                    <span class="rl-lbl">Rare</span>
                    <canvas id="fpop-grad-cv" width="1" height="6" style="flex:1;border-radius:3px;height:6px"></canvas>
                    <span class="rl-lbl">Fréquent</span>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:3px">
                    <span style="font-size:9px;color:rgba(255,255,255,0.35)">Vent faible</span>
                    <span style="font-size:9px;color:rgba(255,255,255,0.35)">Vent fort</span>
                </div>
            </div>
        </template>
    </div>

    {{-- ══ VUE MESURES (station) ══ --}}
    <div class="view" :class="featurePopup.tab === 'meas' ? 'on' : ''">
        <template x-if="fpData && fpData.latest">
            <div class="meas-view">
                <div class="sec-lbl"><i class="ti ti-dashboard" aria-hidden="true"></i>Mesures actuelles</div>
                <div class="params-grid">
                    <div class="pc">
                        <div class="pc-lbl"><i class="ti ti-wind" aria-hidden="true"></i>Vent moy.</div>
                        <div><span class="pc-val" :style="`color:${_spdColor(fpData.latest.wind_speed_avg)}`" x-text="fpData.latest.wind_speed_avg != null ? Math.round(fpData.latest.wind_speed_avg) : '—'"></span><span class="pc-unit">km/h</span></div>
                        <div class="pc-sub" x-text="fpData.latest.wind_direction != null ? (degToCompass(fpData.latest.wind_direction) + ' — ' + Math.round(fpData.latest.wind_direction) + '°') : '—'"></div>
                    </div>
                    <div class="pc">
                        <div class="pc-lbl"><i class="ti ti-bolt" aria-hidden="true"></i>Rafales</div>
                        <div><span class="pc-val" style="color:#fbbf24" x-text="fpData.latest.wind_speed_max != null ? Math.round(fpData.latest.wind_speed_max) : '—'"></span><span class="pc-unit">km/h</span></div>
                    </div>
                    <div class="pc">
                        <div class="pc-lbl"><i class="ti ti-temperature" aria-hidden="true"></i>Temp.</div>
                        <div><span class="pc-val" x-text="fpData.latest.temperature != null ? fpData.latest.temperature.toFixed(1) : '—'"></span><span class="pc-unit">°C</span></div>
                    </div>
                    <div class="pc">
                        <div class="pc-lbl"><i class="ti ti-droplet" aria-hidden="true"></i>Humidité</div>
                        <div><span class="pc-val" style="color:#4ea8e0" x-text="fpData.latest.humidity != null ? fpData.latest.humidity : '—'"></span><span class="pc-unit">%</span></div>
                        <div class="pc-sub" x-show="fpData.latest.dew_point != null" x-cloak x-text="fpData.latest.dew_point != null ? ('Pt rosée ' + Math.round(fpData.latest.dew_point) + '°') : ''"></div>
                    </div>
                    <div class="pc">
                        <div class="pc-lbl"><i class="ti ti-gauge" aria-hidden="true"></i>Pression</div>
                        <div><span class="pc-val" x-text="fpData.latest.pressure_hpa != null ? Math.round(fpData.latest.pressure_hpa) : '—'"></span><span class="pc-unit">hPa</span></div>
                        <div class="pc-sub" x-show="fpPressureTrend" x-cloak x-text="fpPressureTrend"></div>
                    </div>
                    <div class="pc">
                        <div class="pc-lbl"><i class="ti ti-compass" aria-hidden="true"></i>Direction</div>
                        <div><span class="pc-val" style="color:#4ea8e0" x-text="fpData.latest.wind_direction != null ? Math.round(fpData.latest.wind_direction) + '°' : '—'"></span></div>
                        <div class="pc-sub" x-text="fpData.latest.wind_direction != null ? degToCompass(fpData.latest.wind_direction) : ''"></div>
                    </div>
                </div>

                <div class="sec-lbl"><i class="ti ti-history" aria-hidden="true"></i>Évolution 12 h</div>
                <canvas id="fpop-mhist-cv" class="chart-cv" height="70" role="img" aria-label="Mini graphe évolution 12 h du vent"></canvas>
                <div style="display:flex;justify-content:space-between;margin-top:3px;margin-bottom:10px">
                    <span style="font-size:9px;color:rgba(255,255,255,0.42)">−12h</span>
                    <span style="font-size:9px;color:rgba(255,255,255,0.42)">−6h</span>
                    <span style="font-size:9px;color:rgba(255,255,255,0.42)">Maintenant</span>
                </div>
            </div>
        </template>
        <template x-if="!fpData || !fpData.latest">
            <div class="fp-placeholder">Aucune mesure disponible.</div>
        </template>
    </div>

    {{-- ══ VUE ÉVOLUTION (mesures vs consensus J−2 → J+2) ══ --}}
    <div class="view" :class="featurePopup.tab === 'evo' ? 'on' : ''">
        <div class="vtabs">
            <template x-for="v in fpComparVars" :key="v.key">
                <div class="vtab" :class="fpComparVar === v.key ? 'on' : ''" @click="setFpComparVar(v.key)" x-text="v.label"></div>
            </template>
        </div>
        <div x-show="fpComparLoading" class="fp-loader"><div class="fp-spinner"></div></div>
        <template x-if="!fpComparLoading && fpComparData">
            <div>
                <div class="chart-area">
                    <div class="chart-lbl">
                        <span x-text="fpComparVarLabel + ' — mesures vs consensus'"></span>
                        <span style="color:rgba(255,255,255,0.32);font-style:italic;font-size:9px">J−2 → J+2</span>
                    </div>
                    <div class="chart-wrap">
                        <canvas id="fpop-g-cv" class="chart-cv" height="130" role="img" aria-label="Graphique comparatif mesures vs consensus"></canvas>
                        <div class="hl" id="fpop-g-hl"></div>
                        <div class="ctt" id="fpop-g-tt">
                            <div class="ctt-h" id="fpop-g-tt-h">—</div>
                            <div class="ctt-r"><span class="ctt-l" style="color:#4ade80">● Mesure</span><span class="ctt-v" id="fpop-g-tt-m">—</span></div>
                            <div class="ctt-r"><span class="ctt-l">– – Consensus</span><span class="ctt-v" id="fpop-g-tt-c">—</span></div>
                            <div class="ctt-r"><span class="ctt-l">Écart</span><span class="ctt-v" id="fpop-g-tt-e">—</span></div>
                        </div>
                    </div>
                    <div class="days-ax" id="fpop-g-days"></div>
                </div>
                <div class="chart-leg">
                    <div class="cl"><div class="cl-line" style="background:#4ade80"></div>Mesures</div>
                    <div class="cl"><div class="cl-line" style="background:rgba(255,255,255,0.5)"></div>Consensus</div>
                    <div class="cl" style="color:rgba(255,255,255,0.32);font-style:italic"><i class="ti ti-arrow-right" aria-hidden="true" style="font-size:9px"></i>Projection</div>
                </div>
                <div class="fiab">
                    <div class="fiab-icon"><i class="ti ti-certificate" aria-hidden="true"></i></div>
                    <div style="flex:1">
                        <div class="fiab-title">Score de fiabilité consensus</div>
                        <div class="fiab-sub">MAE mesures / prévisions — à venir</div>
                    </div>
                    <div class="fiab-badge">Bientôt</div>
                </div>
            </div>
        </template>
        <template x-if="!fpComparLoading && !fpComparData">
            <div class="fp-placeholder">Données de comparaison indisponibles.</div>
        </template>
    </div>

</div>
