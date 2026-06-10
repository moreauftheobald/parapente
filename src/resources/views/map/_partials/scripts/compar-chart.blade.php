// ── Graphe comparatif « mesures vs consensus » J−2 → J+2 ─────
// Popups balise & station, onglet Évolution. Canvas #fpop-g-cv.
// drawComparChart(measure[40], consensus[40], opts) où opts =
//   { unit, min, max, color, nowStep, nowIdx, days:[{raw,label}] }
// measure : passé seulement (null au-delà de nowStep / sur trous).
// consensus : tout l'axe (null sur trous). Tooltip + axe jours.

(function(){
    const PL = 34, PR = 10, PT = 8, PB = 16, H = 130;

    window.drawComparChart = function(measure, consensus, opts){
        const cv = document.getElementById('fpop-g-cv');
        if(!cv) return;
        const N = measure.length;
        const { unit, min, max, color, nowStep, nowIdx, days } = opts;

        const dpr = window.devicePixelRatio || 1;
        const W = cv.parentElement.offsetWidth || 330;
        cv.width = Math.round(W*dpr); cv.height = Math.round(H*dpr);
        cv.style.width = W+'px'; cv.style.height = H+'px';
        const hl = document.getElementById('fpop-g-hl'); if(hl) hl.style.height = H+'px';
        const ctx = cv.getContext('2d'); ctx.scale(dpr, dpr);
        const CW = W-PL-PR, CH = H-PT-PB;
        const xAt = i => PL + (i/(N-1))*CW;
        const yAt = v => PT + CH - ((v-min)/(max-min))*CH;

        ctx.clearRect(0, 0, W, H);

        // fonds passé / futur
        ctx.fillStyle = 'rgba(255,255,255,0.02)'; ctx.fillRect(PL, PT, xAt(nowStep)-PL, CH);
        ctx.fillStyle = 'rgba(78,168,224,0.03)'; ctx.fillRect(xAt(nowStep), PT, PL+CW-xAt(nowStep), CH);

        // séparateurs de jours
        for(let d = 1; d < 5; d++){
            const x = xAt(d*8);
            ctx.strokeStyle = d === nowIdx ? 'rgba(78,168,224,0.4)' : 'rgba(255,255,255,0.08)';
            ctx.lineWidth = d === nowIdx ? 1 : 0.5;
            ctx.setLineDash(d === nowIdx ? [3,3] : []);
            ctx.beginPath(); ctx.moveTo(x, PT); ctx.lineTo(x, PT+CH); ctx.stroke();
        }
        ctx.setLineDash([]);

        // ligne « maintenant »
        const xn = xAt(nowStep);
        ctx.strokeStyle = 'rgba(255,255,255,0.28)'; ctx.lineWidth = 1; ctx.setLineDash([2,2]);
        ctx.beginPath(); ctx.moveTo(xn, PT); ctx.lineTo(xn, PT+CH); ctx.stroke(); ctx.setLineDash([]);
        ctx.fillStyle = 'rgba(255,255,255,0.42)'; ctx.font = '8px sans-serif'; ctx.textAlign = 'center';
        ctx.fillText('maintenant', xn, PT-1);

        // grilles Y
        for(let i = 0; i <= 4; i++){
            const val = min + (max-min)*i/4, y = yAt(val);
            ctx.strokeStyle = 'rgba(255,255,255,0.05)'; ctx.lineWidth = 0.5;
            ctx.beginPath(); ctx.moveTo(PL, y); ctx.lineTo(PL+CW, y); ctx.stroke();
            ctx.fillStyle = 'rgba(255,255,255,0.48)'; ctx.font = '9px sans-serif'; ctx.textAlign = 'right';
            ctx.fillText(Math.round(val), PL-3, y+3);
        }

        // points mesure (≤ nowStep, non nuls)
        const mPts = [];
        for(let i = 0; i <= nowStep; i++){ if(measure[i] != null) mPts.push({ x:xAt(i), y:yAt(measure[i]) }); }

        // zone remplie sous la mesure
        if(mPts.length){
            ctx.beginPath();
            mPts.forEach((p, i) => i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
            ctx.lineTo(mPts[mPts.length-1].x, PT+CH); ctx.lineTo(mPts[0].x, PT+CH); ctx.closePath();
            const hex = color.replace('#','');
            const r = parseInt(hex.slice(0,2),16), g = parseInt(hex.slice(2,4),16), b = parseInt(hex.slice(4,6),16);
            const grd = ctx.createLinearGradient(0, PT, 0, PT+CH);
            grd.addColorStop(0, `rgba(${r},${g},${b},0.2)`); grd.addColorStop(1, `rgba(${r},${g},${b},0)`);
            ctx.fillStyle = grd; ctx.fill();
        }

        // consensus passé (plein) puis futur (tirets)
        const drawSeg = (from, to, dash) => {
            ctx.beginPath(); ctx.setLineDash(dash);
            let started = false;
            for(let i = from; i <= to; i++){
                if(consensus[i] == null){ started = false; continue; }
                const x = xAt(i), y = yAt(consensus[i]);
                if(!started){ ctx.moveTo(x, y); started = true; } else ctx.lineTo(x, y);
            }
            ctx.stroke(); ctx.setLineDash([]);
        };
        ctx.strokeStyle = 'rgba(255,255,255,0.55)'; ctx.lineWidth = 1.5; drawSeg(0, nowStep, []);
        ctx.strokeStyle = 'rgba(255,255,255,0.38)'; ctx.lineWidth = 1.5; drawSeg(nowStep, N-1, [4,3]);

        // ligne mesure
        if(mPts.length){
            ctx.beginPath(); ctx.strokeStyle = color; ctx.lineWidth = 2;
            mPts.forEach((p, i) => i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
            ctx.stroke();
            const last = mPts[mPts.length-1];
            ctx.beginPath(); ctx.arc(last.x, last.y, 4, 0, Math.PI*2); ctx.fillStyle = color; ctx.fill();
        }

        // ── tooltip ──
        const tt = document.getElementById('fpop-g-tt');
        const move = e => {
            const rect = cv.getBoundingClientRect();
            const mx = (e.clientX ?? e.touches?.[0]?.clientX ?? 0) - rect.left;
            const i = Math.max(0, Math.min(N-1, Math.round((mx*W/rect.width - PL)/CW*(N-1))));
            const xp = xAt(i)/W*rect.width;
            if(hl){ hl.style.display = 'block'; hl.style.left = xp+'px'; }
            if(!tt) return;
            tt.style.display = 'block';
            const lbl = (days?.[Math.floor(i/8)]?.label) ?? '';
            const set = (id, txt, col) => { const el = document.getElementById(id); if(el){ el.textContent = txt; if(col) el.style.color = col; } };
            set('fpop-g-tt-h', lbl + ' — ' + String((i%8)*3).padStart(2,'0') + 'h');
            const mv = (i <= nowStep) ? measure[i] : null, cvv = consensus[i];
            set('fpop-g-tt-m', mv != null ? mv.toFixed(1)+' '+unit : '—', color);
            set('fpop-g-tt-c', cvv != null ? cvv.toFixed(1)+' '+unit : '—');
            const eEl = document.getElementById('fpop-g-tt-e');
            if(eEl){
                if(mv != null && cvv != null){
                    const ec = mv - cvv;
                    eEl.textContent = (ec>=0?'+':'') + ec.toFixed(1) + ' ' + unit;
                    eEl.style.color = Math.abs(ec) < 2 ? '#4ade80' : '#fbbf24';
                } else { eEl.textContent = '—'; eEl.style.color = 'rgba(255,255,255,0.45)'; }
            }
            let left = xp+8; if(left+140 > rect.width) left = xp-148;
            tt.style.left = Math.max(0, left)+'px'; tt.style.top = '4px';
        };
        const leave = () => { if(hl) hl.style.display = 'none'; if(tt) tt.style.display = 'none'; };
        cv.onmousemove = move; cv.onmouseleave = leave;
        cv.ontouchmove = e => { e.preventDefault(); move(e); }; cv.ontouchend = leave;

        // axe jours
        const da = document.getElementById('fpop-g-days');
        if(da){
            da.innerHTML = '';
            (days || []).forEach((d, i) => {
                const el = document.createElement('div');
                el.className = 'day-lbl' + (i === nowIdx ? ' today' : i > nowIdx ? ' future' : '');
                el.textContent = d.label;
                da.appendChild(el);
            });
        }
    };
})();
