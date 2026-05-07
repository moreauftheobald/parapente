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
