        {{-- ═══ DROPDOWNS (position:fixed) ═══════════════ --}}
        <div x-show="dayDropOpen" class="dd-menu" :style="`top:${dayDropPos.top}px;left:${dayDropPos.left}px;min-width:260px;`" @click.stop>
            <template x-for="(day,idx) in days" :key="idx">
                <button class="dd-item" :class="selectedDayIdx===idx?'is-active':''" @click="selectDay(idx);dayDropOpen=false">
                    <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;" :style="{background:day.bestStatus==='green'?'#22c55e':day.bestStatus==='orange'?'#f59e0b':day.bestStatus==='red'?'#ef4444':'#6b7280'}"></span>
                    <span style="flex:1;font-weight:500;" x-text="day.label"></span>
                    <span x-show="day.greenSlots>0" style="color:#4ade80;font-size:11px;font-family:'DM Mono',monospace;" x-text="day.greenSlots+'h'"></span>
                    <span x-show="!day.greenSlots" style="color:#374151;font-size:11px;">—</span>
                    <span x-show="selectedDayIdx===idx" style="color:#4ade80;margin-left:4px;">✓</span>
                </button>
            </template>
        </div>
        <div x-show="bmDropOpen" class="dd-menu" :style="`top:${bmDropPos.top}px;right:${bmDropPos.right}px;width:230px;`" @click.stop>
            <template x-for="bm in basemapList" :key="bm.key">
                <button class="dd-item" :class="currentBasemap===bm.key?'is-active':''" @click="switchBasemap(bm.key);bmDropOpen=false">
                    <span x-text="bm.icon" style="font-size:16px;width:20px;text-align:center;flex-shrink:0;"></span>
                    <div style="flex:1;min-width:0;"><div style="font-weight:500;" x-text="bm.label"></div><div class="dd-sub" x-text="bm.desc"></div></div>
                    <span x-show="currentBasemap===bm.key" style="color:#4ade80;">✓</span>
                </button>
            </template>
        </div>
