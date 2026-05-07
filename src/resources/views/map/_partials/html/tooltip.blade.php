        {{-- ═══ TOOLTIP MULTI-MODÈLES (position:fixed) ═══ --}}
        <div id="chart-tooltip" x-show="tooltip.visible" :style="`top:${tooltip.y}px;left:${tooltip.x}px;`">
            <div class="tt-hour" x-text="tooltip.hour"></div>
            <template x-if="tooltip.consensus !== null">
                <div class="tt-row consensus">
                    <span class="name"><span class="dot"></span>Consensus</span>
                    <span class="val" x-text="tooltip.consensus"></span>
                </div>
            </template>
            <template x-for="row in tooltip.rows" :key="row.id">
                <div class="tt-row">
                    <span class="name"><span class="dot" :style="`background:${row.color}`"></span><span x-text="row.name"></span></span>
                    <span class="val" x-text="row.value"></span>
                </div>
            </template>
            <div x-show="!tooltip.rows.length && tooltip.consensus===null" class="tt-empty">Aucune donnée</div>
        </div>
