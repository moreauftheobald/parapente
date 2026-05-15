            {{-- ═══ VOLET GAUCHE : onglets Paramètres / Légende ═══ --}}
            {{-- Le wrapper et le bouton ☰ sont fournis par <x-app-shell>. Ici
                 on ne déclare que le contenu (onglets, sections). Le détail
                 d'un site / d'une balise s'affiche dans le volet DROIT. --}}
            <div id="left-panel">
                <div class="lp-body">

                    <div class="lp-tabs">
                        <button class="lp-tab" :class="lpTab === 'params' ? 'active' : ''" @click="lpTab = 'params'">Paramètres</button>
                        <button class="lp-tab" :class="lpTab === 'legend' ? 'active' : ''" @click="lpTab = 'legend'">Légende</button>
                    </div>

                    {{-- ═══════ Onglet PARAMÈTRES ═══════ --}}
                    <div class="lp-tabpane" x-show="lpTab === 'params'">

                        {{-- Sélecteur de jour — visible uniquement sur mobile,
                             où il a quitté la carte pour rejoindre le volet. --}}
                        <div class="lp-section lp-section-mobile-only">
                            <div class="lp-title">Jour</div>
                            <button class="dd-trigger" style="width:100%;" @click.stop="toggleDayDrop($el)">
                                <span style="width:10px;height:10px;border-radius:50%;flex-shrink:0;"
                                      :style="{background:days[selectedDayIdx]?.bestStatus==='green'?'#22c55e':days[selectedDayIdx]?.bestStatus==='orange'?'#f59e0b':days[selectedDayIdx]?.bestStatus==='red'?'#ef4444':'#6b7280'}"></span>
                                <div style="flex:1;text-align:left;min-width:0;">
                                    <div style="font-weight:500;color:#fff;font-size:14px;" x-text="days[selectedDayIdx]?.label??'Chargement…'"></div>
                                    <div style="font-size:11px;margin-top:1px;">
                                        <span x-show="days[selectedDayIdx]?.greenSlots>0" style="color:#4ade80;" x-text="days[selectedDayIdx]?.greenSlots+'h de vol possible'"></span>
                                        <span x-show="!days[selectedDayIdx]?.greenSlots" style="color:#4b5563;">Aucun créneau favorable</span>
                                    </div>
                                </div>
                                <span class="dd-arrow" :class="dayDropOpen?'open':''">▼</span>
                            </button>
                        </div>

                        <div class="lp-section">
                            <div class="lp-title">Réseaux de balises</div>
                            <template x-for="net in BALISE_NETWORKS" :key="net.key">
                                <button class="lp-toggle"
                                        :class="networksVisible[net.key] ? 'is-on' : ''"
                                        @click="toggleNetwork(net.key)"
                                        :title="(networksVisible[net.key] ? 'Masquer' : 'Afficher') + ' les balises ' + net.label">
                                    <span class="lp-lbl">
                                        <span x-text="net.icon" style="margin-right:4px"></span>
                                        <span x-text="net.label"></span>
                                    </span>
                                    <span class="lp-count mono" x-text="balisesCountByNetwork(net.key) || ''"></span>
                                    <span class="lp-switch"></span>
                                </button>
                            </template>
                        </div>

                        <div class="lp-section">
                            <div class="lp-title">Sites affichés</div>
                            <button class="lp-toggle" :class="showGreen ? 'is-on' : ''" @click="showGreen = !showGreen; renderMarkers()">
                                <span class="dot dot-green"></span>
                                <span class="lp-lbl">Sites favorables</span>
                                <span class="lp-switch"></span>
                            </button>
                            <button class="lp-toggle" :class="showOrange ? 'is-on' : ''" @click="showOrange = !showOrange; renderMarkers()">
                                <span class="dot dot-orange"></span>
                                <span class="lp-lbl">Sites incertains</span>
                                <span class="lp-switch"></span>
                            </button>
                            <button class="lp-toggle" :class="showRed ? 'is-on' : ''" @click="showRed = !showRed; renderMarkers()">
                                <span class="dot dot-red"></span>
                                <span class="lp-lbl">Sites défavorables</span>
                                <span class="lp-switch"></span>
                            </button>
                        </div>

                        <div class="lp-section">
                            <div class="lp-title">Fond de carte</div>
                            <button class="dd-trigger" style="width:100%;" @click.stop="toggleBmDrop($el)">
                                <span x-text="currentBasemapObj.icon" style="font-size:16px;line-height:1;flex-shrink:0;"></span>
                                <span style="flex:1;text-align:left;" x-text="currentBasemapObj.label"></span>
                                <span class="dd-arrow" :class="bmDropOpen ? 'open' : ''">▼</span>
                            </button>
                        </div>

                        <div class="lp-section">
                            <div class="lp-title">Filtres</div>
                            <template x-if="authUser">
                                <button class="lp-toggle" :class="onlyMyScorings ? 'is-on' : ''"
                                        @click="toggleOnlyMyScorings()"
                                        title="Afficher seulement les sites sur lesquels j'ai un scoring perso (actif ou non).">
                                    <span class="dot dot-mine"></span>
                                    <span class="lp-lbl">Mes sites uniquement</span>
                                    <span class="lp-switch"></span>
                                </button>
                            </template>
                            <template x-if="!authUser">
                                <div class="lp-note">
                                    <a href="{{ route('login') }}" style="color:#60a5fa;text-decoration:underline;">Connecte-toi</a>
                                    pour activer le filtre « Mes sites ».
                                </div>
                            </template>
                        </div>

                    </div>

                    {{-- ═══════ Onglet LÉGENDE ═══════ --}}
                    <div class="lp-tabpane" x-show="lpTab === 'legend'">

                        <div class="lp-section">
                            <div class="lp-title">Sites de vol</div>
                            <div class="lp-legend-icons">
                                <div class="lp-li">
                                    <span class="lp-icon-ex"><span class="pg-site-marker pg-status-green"><img src="https://www.spotair.mobi/icones/spots/spot.svg.php?p=1&amp;t=1" alt=""></span></span>
                                    <span>Conditions favorables</span>
                                </div>
                                <div class="lp-li">
                                    <span class="lp-icon-ex"><span class="pg-site-marker pg-status-orange"><img src="https://www.spotair.mobi/icones/spots/spot.svg.php?p=1&amp;t=1" alt=""></span></span>
                                    <span>Conditions incertaines</span>
                                </div>
                                <div class="lp-li">
                                    <span class="lp-icon-ex"><span class="pg-site-marker pg-status-red"><img src="https://www.spotair.mobi/icones/spots/spot.svg.php?p=1&amp;t=1" alt=""></span></span>
                                    <span>Conditions défavorables</span>
                                </div>
                                <div class="lp-li">
                                    <span class="lp-icon-ex"><span class="pg-site-marker pg-status-unknown"><img src="https://www.spotair.mobi/icones/spots/spot.svg.php?p=1&amp;t=1" alt=""></span></span>
                                    <span>Données indisponibles</span>
                                </div>
                            </div>
                            <div class="lp-note">Le bouclier indique le niveau recommandé du site et ses orientations de vent favorables ; le halo coloré reflète la météo du jour sélectionné.</div>
                        </div>

                        <template x-if="authUser">
                            <div class="lp-section">
                                <div class="lp-title">Badges scoring perso</div>
                                <div class="lp-legend-icons">
                                    <div class="lp-li">
                                        <span class="lp-icon-ex" style="position:relative;">
                                            <span class="pg-site-marker pg-status-unknown">
                                                <img src="https://www.spotair.mobi/icones/spots/spot.svg.php?p=1&amp;t=1" alt="">
                                                <span class="pg-user-badge pg-user-badge-active"></span>
                                            </span>
                                        </span>
                                        <span>Scoring perso actif sur ce site</span>
                                    </div>
                                    <div class="lp-li">
                                        <span class="lp-icon-ex" style="position:relative;">
                                            <span class="pg-site-marker pg-status-unknown">
                                                <img src="https://www.spotair.mobi/icones/spots/spot.svg.php?p=1&amp;t=1" alt="">
                                                <span class="pg-user-badge pg-user-badge-inactive"></span>
                                            </span>
                                        </span>
                                        <span>Scoring perso enregistré (inactif)</span>
                                    </div>
                                </div>
                                <div class="lp-note">Quand un scoring perso est actif, le halo et le détail du scoring reflètent <strong>tes</strong> conditions au lieu des conditions standard du site.</div>
                            </div>
                        </template>

                        <div class="lp-section">
                            <div class="lp-title">Balises météo — vent</div>
                            <div class="lp-legend-icons">
                                <div class="lp-li">
                                    <img class="lp-balise-ex" src="https://www.spotair.mobi/icones/balises/balise.svg.php?d=90&amp;v=3&amp;t=0&amp;bg=w&amp;c=b" alt="">
                                    <span>Vent faible (&lt; 5 km/h)</span>
                                </div>
                                <div class="lp-li">
                                    <img class="lp-balise-ex" src="https://www.spotair.mobi/icones/balises/balise.svg.php?d=90&amp;v=12&amp;t=0&amp;bg=w&amp;c=g" alt="">
                                    <span>Vent modéré (5–20 km/h)</span>
                                </div>
                                <div class="lp-li">
                                    <img class="lp-balise-ex" src="https://www.spotair.mobi/icones/balises/balise.svg.php?d=90&amp;v=25&amp;t=0&amp;bg=w&amp;c=o" alt="">
                                    <span>Vent fort (&gt; 20 km/h)</span>
                                </div>
                            </div>
                            <div class="lp-note">La flèche pointe dans la direction vers laquelle souffle le vent.</div>
                        </div>

                        <div class="lp-section">
                            <div class="lp-title">Balises météo — fraîcheur</div>
                            <div class="lp-legend-icons">
                                <div class="lp-li">
                                    <img class="lp-balise-ex" src="https://www.spotair.mobi/icones/balises/balise.svg.php?d=90&amp;v=12&amp;t=0&amp;bg=w&amp;c=g" alt="">
                                    <span>Relevé récent (&lt; 30 min)</span>
                                </div>
                                <div class="lp-li">
                                    <img class="lp-balise-ex" src="https://www.spotair.mobi/icones/balises/balise.svg.php?d=90&amp;v=12&amp;t=0&amp;bg=l&amp;c=g" alt="">
                                    <span>Relevé en retard</span>
                                </div>
                                <div class="lp-li">
                                    <img class="lp-balise-ex" src="https://www.spotair.mobi/icones/balises/balise.svg.php?d=0&amp;v=0&amp;t=0&amp;bg=d&amp;c=g" alt="">
                                    <span>Balise hors service</span>
                                </div>
                            </div>
                        </div>

                        <div class="lp-section">
                            <div class="lp-title">Fenêtre de vol</div>
                            <div class="lp-note">Les créneaux sont évalués entre le lever du soleil −30 min et le coucher +30 min.</div>
                        </div>

                    </div>

                </div>

            </div>
