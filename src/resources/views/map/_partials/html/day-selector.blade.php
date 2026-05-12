            {{-- ═══ SÉLECTEUR DE JOUR (flottant, coin haut-droite de la carte) ═══ --}}
            <div id="day-selector">
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
            </div>
