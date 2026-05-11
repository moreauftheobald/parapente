            {{-- ── POPUP BALISE (relevés temps réel + historique du jour) ── --}}
            <div id="balise-popup" x-show="balisePopupOpen"
                 :style="`top:${balisePos.top}px;left:${balisePos.left}px;`"
                 @click.stop>

                {{-- Header --}}
                <div style="padding:16px 18px 12px;border-bottom:1px solid rgba(55,65,81,.4);display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                    <div>
                        <div style="color:#fff;font-size:17px;font-weight:600;line-height:1.2;" x-text="baliseData?.balise?.name ?? '…'"></div>
                        <div style="font-size:12px;color:#9ca3af;margin-top:4px;">
                            <span x-text="(baliseData?.balise?.source ?? '').toUpperCase()"></span>
                            <template x-if="baliseData?.balise?.altitude_m"><span> · <span x-text="baliseData.balise.altitude_m + ' m'"></span></span></template>
                            <span> · </span><span x-text="baliseFreshness()"></span>
                        </div>
                    </div>
                    <button @click="balisePopupOpen=false"
                            style="width:30px;height:30px;border-radius:50%;border:none;background:transparent;color:#cbd5e1;cursor:pointer;font-size:16px;flex-shrink:0;"
                            onmouseover="this.style.background='rgba(55,65,81,.7)';this.style.color='#fff'"
                            onmouseout="this.style.background='transparent';this.style.color='#cbd5e1'">✕</button>
                </div>

                {{-- Loader --}}
                <div x-show="baliseLoading" style="padding:46px;display:flex;align-items:center;justify-content:center;">
                    <div style="width:28px;height:28px;border:2px solid #374151;border-top-color:#38bdf8;border-radius:50%;animation:spin 1s linear infinite;"></div>
                </div>

                <template x-if="!baliseLoading && baliseData">
                    <div>
                        {{-- Bloc "maintenant" --}}
                        <template x-if="baliseData.latest">
                            <div style="padding:8px 18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                                {{-- Flèche direction --}}
                                <div style="display:flex;flex-direction:column;align-items:center;gap:1px;">
                                    <svg width="36" height="36" viewBox="-22 -22 44 44" style="overflow:visible;">
                                        <circle r="20" fill="none" stroke="#374151" stroke-width="1"/>
                                        <g x-show="baliseData.latest.wind_direction !== null"
                                           :transform="`rotate(${((baliseData.latest.wind_direction ?? 0) + 180) % 360})`">
                                            <polygon points="0,-15 7,7 0,2 -7,7" fill="#38bdf8"/>
                                        </g>
                                    </svg>
                                    <span style="font-family:'DM Mono',monospace;font-size:11px;color:#cbd5e1;"
                                          x-text="baliseData.latest.wind_direction !== null ? (Math.round(baliseData.latest.wind_direction) + '° ' + degToCompass(baliseData.latest.wind_direction)) : '—'"></span>
                                </div>
                                {{-- Vitesse --}}
                                <div>
                                    <div style="display:flex;align-items:baseline;gap:6px;">
                                        <span style="font-size:26px;font-weight:600;color:#fff;font-family:'DM Mono',monospace;line-height:1;"
                                              x-text="baliseData.latest.wind_speed_avg !== null ? baliseData.latest.wind_speed_avg.toFixed(1) : '—'"></span>
                                        <span style="font-size:12px;color:#9ca3af;">km/h</span>
                                        <span x-text="baliseTrendArrow()" :style="`font-size:15px;color:${baliseTrendColor()};`"></span>
                                    </div>
                                    <div style="font-family:'DM Mono',monospace;font-size:11.5px;color:#9ca3af;margin-top:1px;">
                                        rafales <span x-text="baliseData.latest.wind_speed_max !== null ? Math.round(baliseData.latest.wind_speed_max) : '—'"></span> ·
                                        min <span x-text="baliseData.latest.wind_speed_min !== null ? Math.round(baliseData.latest.wind_speed_min) : '—'"></span>
                                    </div>
                                </div>
                                {{-- Temp / humidité --}}
                                <div style="font-family:'DM Mono',monospace;font-size:12px;color:#cbd5e1;line-height:1.45;">
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
                            <div style="padding:18px;color:#9ca3af;font-size:13px;">Aucun relevé disponible.</div>
                        </template>

                        {{-- Direction du vent : cadran 8 branches, ligne heure par heure --}}
                        <div x-show="baliseData.readings && baliseData.readings.length"
                             style="padding:6px 18px 4px;display:grid;grid-template-columns:188px 1fr;gap:14px;align-items:center;">
                            <svg id="balise-rose-svg" width="188" height="188" viewBox="0 0 200 200" style="display:block;overflow:visible;"></svg>
                            <div style="font-size:11.5px;color:#9ca3af;line-height:1.5;min-width:0;">
                                <div style="color:#e5e7eb;font-size:12.5px;margin-bottom:4px;">Direction du vent — heure par heure</div>
                                <div>Angle = direction d'où vient le vent</div>
                                <div>Rayon = vitesse moyenne (km/h)</div>
                                <div style="margin-top:7px;display:flex;align-items:center;gap:6px;">
                                    <span>matin</span>
                                    <span style="display:inline-block;width:60px;height:8px;border-radius:4px;background:linear-gradient(90deg,#38bdf8,#f59e0b);"></span>
                                    <span>soir</span>
                                </div>
                                <div style="margin-top:3px;">● blanc = dernier relevé</div>
                                <div style="margin-top:7px;">
                                    <span style="color:#f97316;">▮</span> rafales &nbsp;<span style="color:#22c55e;">▬</span> moyen &nbsp;<span style="color:#3b82f6;">▬</span> min
                                </div>
                                <div style="margin-top:3px;color:#6b7280;">Survolez le graphe ↓ pour pointer une heure</div>
                            </div>
                        </div>

                        {{-- Graphe vitesse du jour --}}
                        <div x-show="baliseData.readings && baliseData.readings.length" style="padding:0 18px 8px;">
                            <div style="font-size:12px;color:#e5e7eb;margin-bottom:2px;"
                                 x-text="baliseData.fallback ? 'Vitesse — dernières 24 h' : 'Vitesse — relevés du jour'"></div>
                            <svg id="balise-chart-svg" width="558" height="178" viewBox="0 0 440 140" style="display:block;overflow:visible;"></svg>
                        </div>

                        {{-- Pied --}}
                        <div style="padding:8px 18px;border-top:1px solid rgba(55,65,81,.4);font-size:12px;color:#9ca3af;">
                            <span x-text="(baliseData.readings?.length ?? 0) + (baliseData.fallback ? ' relevé(s) sur 24 h' : ' relevé(s) aujourd\'hui')"></span>
                        </div>
                    </div>
                </template>
            </div>
