{{-- ═══ Rose des vents animée — modale (refonte v2) ═══ --}}
{{-- Ouverte depuis la mini-rose de l'onglet Synthèse (openWindRose).
     Rendu SVG/stats/timeline piloté en JS (panel-windrose). --}}
<div class="wr-modal" x-show="windRoseOpen" x-cloak
     @click.self="closeWindRose()"
     @keydown.escape.window="windRoseOpen && closeWindRose()">
    <div class="wr-panel">

        {{-- TOPBAR --}}
        <div class="wr-topbar">
            <div class="wr-site">
                <div class="wr-site-name" x-text="selectedFeature?.name ?? ''"></div>
                <div class="wr-site-meta">
                    <template x-if="selectedFeature?.altitude != null">
                        <span><i class="ti ti-mountain" aria-hidden="true"></i><span x-text="selectedFeature.altitude + ' m'"></span></span>
                    </template>
                    <template x-if="siteOrientationLabel">
                        <span><i class="ti ti-compass" aria-hidden="true"></i><span x-text="'Axe ' + siteOrientationLabel"></span></span>
                    </template>
                    <template x-if="panelSunWindow">
                        <span><i class="ti ti-sun" aria-hidden="true"></i><span x-text="(panelSunWindow.sunrise_display ?? '—') + ' – ' + (panelSunWindow.sunset_display ?? '—')"></span></span>
                    </template>
                </div>
            </div>
            <div class="wr-controls">
                <button class="wr-btn" @click="wrStep(-1)" aria-label="Heure précédente" title="Heure précédente"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>
                <div class="wr-time" id="wr-time-lbl">—</div>
                <button class="wr-btn" @click="wrStep(1)" aria-label="Heure suivante" title="Heure suivante"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>
                <button class="wr-btn" :class="wrPlaying ? 'playing' : ''" @click="wrTogglePlay()" aria-label="Lecture automatique" title="Lecture"><i class="ti" :class="wrPlaying ? 'ti-player-pause' : 'ti-player-play'" aria-hidden="true"></i></button>
                <button class="wr-close" @click="closeWindRose()" aria-label="Fermer" title="Fermer"><i class="ti ti-x" aria-hidden="true"></i></button>
            </div>
        </div>

        {{-- CORPS --}}
        <div class="wr-body">
            <div class="wr-rose-col">
                <div class="wr-svg-wrap">
                    <svg class="wr-svg" viewBox="0 0 230 230" role="img" aria-label="Rose des vents animée — direction et intensité du vent heure par heure">
                        {{-- rings vitesse --}}
                        <circle cx="115" cy="115" r="98" fill="none" stroke="rgba(255,255,255,0.04)" stroke-width="1"/>
                        <circle cx="115" cy="115" r="70" fill="none" stroke="rgba(255,255,255,0.04)" stroke-width="1"/>
                        <circle cx="115" cy="115" r="44" fill="none" stroke="rgba(255,255,255,0.04)" stroke-width="1"/>
                        <circle cx="115" cy="115" r="20" fill="none" stroke="rgba(255,255,255,0.04)" stroke-width="1"/>
                        {{-- axes cardinaux --}}
                        <line x1="115" y1="17" x2="115" y2="213" stroke="rgba(255,255,255,0.045)" stroke-width="0.5"/>
                        <line x1="17" y1="115" x2="213" y2="115" stroke="rgba(255,255,255,0.045)" stroke-width="0.5"/>
                        <line x1="44" y1="44" x2="186" y2="186" stroke="rgba(255,255,255,0.025)" stroke-width="0.5"/>
                        <line x1="186" y1="44" x2="44" y2="186" stroke="rgba(255,255,255,0.025)" stroke-width="0.5"/>
                        {{-- labels vitesse --}}
                        <text x="115" y="70" text-anchor="middle" font-size="8" fill="rgba(255,255,255,0.55)">35</text>
                        <text x="115" y="94" text-anchor="middle" font-size="8" fill="rgba(255,255,255,0.14)">22</text>
                        {{-- labels cardinaux --}}
                        <text x="115" y="12" text-anchor="middle" font-size="11" font-weight="500" fill="rgba(255,255,255,0.55)">N</text>
                        <text x="115" y="228" text-anchor="middle" font-size="11" fill="rgba(255,255,255,0.55)">S</text>
                        <text x="226" y="119" text-anchor="middle" font-size="11" fill="rgba(255,255,255,0.55)">E</text>
                        <text x="4" y="119" text-anchor="middle" font-size="11" fill="rgba(255,255,255,0.55)">O</text>
                        <text x="183" y="36" text-anchor="middle" font-size="9" fill="rgba(255,255,255,0.14)">NE</text>
                        <text x="198" y="198" text-anchor="middle" font-size="9" fill="rgba(255,255,255,0.14)">SE</text>
                        <text x="32" y="198" text-anchor="middle" font-size="9" fill="rgba(255,255,255,0.14)">SO</text>
                        <text x="32" y="36" text-anchor="middle" font-size="9" fill="rgba(255,255,255,0.14)">NO</text>
                        {{-- secteur axe du site (dynamique) --}}
                        <path id="wr-axis-sector" fill="rgba(78,168,224,0.08)" stroke="rgba(78,168,224,0.2)" stroke-width="0.5" d=""/>
                        {{-- traîne historique --}}
                        <g id="wr-trail"></g>
                        {{-- cercle rafales --}}
                        <circle id="wr-gust-ring" cx="115" cy="115" r="0" fill="none" stroke="rgba(78,168,224,0.22)" stroke-width="1.5"/>
                        {{-- flèche vent moyen --}}
                        <line id="wr-arrow-line" x1="115" y1="115" x2="115" y2="115" stroke="#4ade80" stroke-width="2.5" stroke-linecap="round"/>
                        <polygon id="wr-arrow-head" points="115,115" fill="#4ade80"/>
                        <circle id="wr-center" cx="115" cy="115" r="5" fill="#4ade80"/>
                    </svg>
                </div>
                <div class="wr-rose-legend">
                    <div class="wr-leg"><div class="wr-leg-line" style="background:#4ade80"></div>Vent moyen</div>
                    <div class="wr-leg"><div class="wr-leg-ring"></div>Rafales</div>
                    <div class="wr-leg"><div class="wr-leg-sector"></div>Axe site</div>
                    <div class="wr-leg"><div class="wr-leg-dot" style="background:rgba(78,168,224,0.35)"></div>Historique</div>
                </div>
            </div>

            {{-- STATS --}}
            <div class="wr-stats-col">
                <div class="wr-stat" id="stat-dir">
                    <div class="wr-stat-label"><i class="ti ti-compass" aria-hidden="true"></i>Direction</div>
                    <div><span class="wr-stat-val" id="stat-dir-val" style="color:#e8f4fd">—</span><span class="wr-stat-unit" id="stat-dir-card"></span></div>
                    <div class="wr-stat-sub" id="stat-dir-axe" style="color:#4ade80">—</div>
                </div>
                <div class="wr-stat">
                    <div class="wr-stat-label"><i class="ti ti-wind" aria-hidden="true"></i>Vent moyen</div>
                    <div><span class="wr-stat-val" id="stat-spd-val">—</span><span class="wr-stat-unit">km/h</span></div>
                    <div class="wr-bar-bg"><div class="wr-bar-fill" id="bar-spd" style="width:0%"></div></div>
                </div>
                <div class="wr-stat">
                    <div class="wr-stat-label"><i class="ti ti-bolt" aria-hidden="true"></i>Rafales max</div>
                    <div><span class="wr-stat-val" id="stat-gust-val" style="color:#fbbf24">—</span><span class="wr-stat-unit">km/h</span></div>
                    <div class="wr-bar-bg"><div class="wr-bar-fill" id="bar-gust" style="width:0%"></div></div>
                </div>
                <div class="wr-stat accent">
                    <div class="wr-stat-label"><i class="ti ti-arrow-bar-up" aria-hidden="true"></i>Plafond estimé</div>
                    <div><span class="wr-stat-val" id="stat-ceil-val" style="color:#4ea8e0">—</span><span class="wr-stat-unit">m</span></div>
                    <div class="wr-stat-sub" id="stat-ceil-sub">— m / décollage</div>
                </div>
            </div>
        </div>

        {{-- TIMELINE --}}
        <div class="wr-timeline">
            <div class="wr-tl-header"><span>Vent moyen · rafales · axe</span><span id="wr-tl-range">—</span></div>
            <div class="wr-tl-track" id="wr-tl-track"></div>
            <div class="wr-tl-dir" id="wr-tl-dir"></div>
            <div class="wr-tl-hours" id="wr-tl-hours"></div>
        </div>

        {{-- VERDICT --}}
        <div class="wr-verdict">
            <div class="wr-verdict-dot" id="wr-verdict-dot" style="background:#16a34a"></div>
            <div class="wr-verdict-text" id="wr-verdict-text">—</div>
        </div>

    </div>
</div>
