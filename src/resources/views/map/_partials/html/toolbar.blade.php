        {{-- ═══ TOOLBAR ════════════════════════════════ --}}
        <div id="pg-toolbar">
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
            <div style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:6px;">
                <span style="width:6px;height:6px;border-radius:50%;background:#22c55e;"></span>
                <span style="color:#4ade80;font-weight:500;" x-text="greenCount"></span>
                <span>/ <span x-text="sites.length"></span> volables</span>
            </div>
            <div style="flex:1;"></div>
            <button class="dd-trigger pg-toggle" :class="balisesVisible?'is-on':''"
                    style="padding:8px 14px;"
                    @click.stop="toggleBalises()"
                    :title="balisesVisible?'Masquer les balises météo':'Afficher les balises météo'">
                <span style="font-size:14px;line-height:1;" x-text="balisesVisible?'🪁':'🪁'"></span>
                <span style="font-size:13px;font-weight:500;">Balises</span>
                <span style="font-size:11px;color:#6b7280;font-family:'DM Mono',monospace;" x-text="balises.length||''"></span>
            </button>
            <div style="width:1px;height:24px;background:rgba(75,85,99,.4);"></div>
            <button class="dd-trigger" style="min-width:190px;" @click.stop="toggleBmDrop($el)">
                <span x-text="currentBasemapObj.icon" style="font-size:16px;line-height:1;flex-shrink:0;"></span>
                <span style="flex:1;text-align:left;" x-text="currentBasemapObj.label"></span>
                <span class="dd-arrow" :class="bmDropOpen?'open':''">▼</span>
            </button>
        </div>
