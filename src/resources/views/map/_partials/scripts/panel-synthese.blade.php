// ── Panneau droit v2 — onglet « Synthèse » ───────────────────
// Renderers canvas (nuages + vent double-axe) pour le nouveau panneau
// droit. Reçoivent l'instance Alpine `app` et lisent app.synthDayData
// (données horaires du jour sélectionné, clippées à la fenêtre solaire).
// Aucune dépendance externe ; DPR géré ; re-render au changement de
// site / jour / onglet / resize (cf. app.renderSynthese).

(function(){
    const PAD_L = 32, PAD_R = 40, CH = 140, MAX_W = 45;
    // Géométrie du dernier rendu du graphe vent (pour le tooltip). Un seul
    // panneau droit à la fois → une variable de closure suffit.
    let WIND = null;

    function spdCol(s){ return s < 12 ? '#4ade80' : s < 22 ? '#4ea8e0' : s < 32 ? '#fbbf24' : '#f87171'; }
    function mrgCol(m){ return m > 800 ? '#4ade80' : m > 400 ? '#fbbf24' : '#f87171'; }

    // ── Nuages : une colonne canvas par heure, dégradé vertical 3 couches
    //    (bas / moyen / haut). Encodage INVERSÉ : bleu lumineux = dégagé,
    //    sombre = couvert. ───────────────────────────────────────────────
    window.buildSynthClouds = function(app){
        const data = app.synthDayData;
        const wrap = document.getElementById('rp2-cloud-wrap');
        const ax   = document.getElementById('rp2-cloud-ax');
        if(!wrap || !ax) return;
        wrap.innerHTML = '';
        ax.innerHTML   = '';
        if(!data.length) return;

        data.forEach(() => {
            const col = document.createElement('div'); col.className = 'cloud-col';
            const cv  = document.createElement('canvas'); col.appendChild(cv);
            wrap.appendChild(col);
        });

        // ~5 graduations d'heures réparties
        const n = data.length;
        const idxs = [...new Set([0, Math.round(n*0.25), Math.round(n*0.5), Math.round(n*0.75), n-1])];
        idxs.forEach(i => {
            const s = document.createElement('span');
            s.textContent = parseInt(data[i].hour, 10) + 'h';
            ax.appendChild(s);
        });

        requestAnimationFrame(() => {
            const dpr = window.devicePixelRatio || 1;
            wrap.querySelectorAll('.cloud-col').forEach((col, i) => {
                const cv = col.querySelector('canvas');
                const W  = col.offsetWidth || 18, H = 42;
                cv.width = Math.round(W*dpr); cv.height = Math.round(H*dpr);
                cv.style.width = W+'px'; cv.style.height = H+'px';
                const ctx = cv.getContext('2d'); ctx.scale(dpr, dpr);
                const cl = (data[i].cloud_low  ?? 0)/100,
                      cm = (data[i].cloud_mid  ?? 0)/100,
                      ch = (data[i].cloud_high ?? 0)/100;
                const avg = (cl+cm+ch)/3;
                const cr = Math.round(14 + (1-avg)*50),
                      cg = Math.round(40 + (1-avg)*100),
                      cb = Math.round(80 + (1-avg)*120);
                ctx.fillStyle = `rgb(${cr},${cg},${cb})`; ctx.fillRect(0, 0, W, H);
                const g = ctx.createLinearGradient(0, H, 0, 0);
                g.addColorStop(0,    `rgba(18,25,42,${(cl*.82).toFixed(2)})`);
                g.addColorStop(0.35, `rgba(18,25,42,${(cl*.5).toFixed(2)})`);
                g.addColorStop(0.5,  `rgba(12,18,32,${(cm*.75).toFixed(2)})`);
                g.addColorStop(0.72, `rgba(10,15,28,${((cm+ch)/2*.65).toFixed(2)})`);
                g.addColorStop(1,    `rgba(8,12,24,${(ch*.68).toFixed(2)})`);
                ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
            });
        });
    };

    // ── Vent double-axe : barres vent moyen (vert) + delta rafales (ambre),
    //    ligne plafond (#60a5fa), ligne décollage (pointillé), flèches de
    //    direction + bande axe/hors-axe + tooltip au survol. ──────────────
    window.buildSynthWind = function(app){
        const canvas = document.getElementById('rp2-wind-cv');
        if(!canvas) return;
        const data = app.synthDayData;
        const N = data.length;
        if(!N){ return; }

        const decollage = app.selectedFeature?.altitude ?? 0;
        const ceils = data.map(d => d.cloud_base).filter(v => v != null);
        const lo = Math.min(decollage, ceils.length ? Math.min(...ceils) : decollage);
        const hi = Math.max(decollage, ceils.length ? Math.max(...ceils) : decollage);
        const pad = Math.max(150, (hi - lo) * 0.15);
        const MIN_A = Math.max(0, Math.floor((lo - pad)/100)*100);
        const MAX_A = Math.ceil((hi + pad)/100)*100 || (MIN_A + 1000);
        const span  = (MAX_A - MIN_A) || 1000;

        const wy = v => CH - (v/MAX_W)*CH;
        const ay = v => CH - ((v - MIN_A)/span)*CH;

        const dpr = window.devicePixelRatio || 1;
        const W = canvas.parentElement.offsetWidth || 350;
        canvas.width = Math.round(W*dpr); canvas.height = Math.round(CH*dpr);
        canvas.style.width = W+'px'; canvas.style.height = CH+'px';
        const hl = document.getElementById('rp2-hover-line');
        if(hl) hl.style.height = CH+'px';
        const ctx = canvas.getContext('2d'); ctx.scale(dpr, dpr);
        const CW = W - PAD_L - PAD_R;
        const bw = CW / N;

        ctx.clearRect(0, 0, W, CH);

        // grilles
        [20, 40].forEach(v => { ctx.strokeStyle='rgba(255,255,255,0.05)'; ctx.lineWidth=0.5; ctx.setLineDash([]); ctx.beginPath(); ctx.moveTo(PAD_L, wy(v)); ctx.lineTo(PAD_L+CW, wy(v)); ctx.stroke(); });

        // graduations altitude (4 paliers ronds)
        const altTicks = [0,1,2,3].map(i => Math.round((MIN_A + span*i/3)/100)*100);
        altTicks.forEach(v => { ctx.strokeStyle='rgba(255,255,255,0.04)'; ctx.lineWidth=0.5; ctx.beginPath(); ctx.moveTo(PAD_L, ay(v)); ctx.lineTo(PAD_L+CW, ay(v)); ctx.stroke(); });

        // axe Y gauche (km/h)
        ctx.fillStyle='rgba(255,255,255,0.55)'; ctx.font='9px sans-serif'; ctx.textAlign='right';
        [0,20,40].forEach(v => ctx.fillText(v, PAD_L-4, wy(v)+3));
        ctx.fillStyle='rgba(255,255,255,0.16)'; ctx.font='8px sans-serif'; ctx.fillText('km/h', PAD_L-4, CH-1);

        // axe Y droit (altitude)
        ctx.textAlign='left'; ctx.fillStyle='rgba(96,165,250,0.65)'; ctx.font='9px sans-serif';
        altTicks.forEach(v => { const y=ay(v); if(y>=2 && y<=CH-2) ctx.fillText((v/1000).toFixed(1)+'k', PAD_L+CW+4, y+3); });
        ctx.fillStyle='rgba(96,165,250,0.45)'; ctx.font='8px sans-serif'; ctx.fillText('m', PAD_L+CW+4, CH-1);

        // barres vent
        data.forEach((d, i) => {
            const x = PAD_L + i*bw;
            const mean = d.wind_avg, gust = d.wind_max;
            if(gust != null && mean != null){
                const yG = wy(gust), yM = wy(mean);
                ctx.fillStyle='rgba(251,191,36,0.55)'; ctx.fillRect(x+1, yG, bw-2, yM-yG);
                ctx.fillStyle=spdCol(mean); ctx.globalAlpha=0.88; ctx.fillRect(x+1, yM, bw-2, CH-yM); ctx.globalAlpha=1;
            } else if(mean != null){
                const yM = wy(mean);
                ctx.fillStyle=spdCol(mean); ctx.globalAlpha=0.88; ctx.fillRect(x+1, yM, bw-2, CH-yM); ctx.globalAlpha=1;
            }
        });

        // ligne plafond
        ctx.beginPath(); ctx.strokeStyle='#60a5fa'; ctx.lineWidth=1.8; ctx.lineJoin='round'; ctx.setLineDash([]);
        let started = false;
        data.forEach((d, i) => {
            if(d.cloud_base == null){ started = false; return; }
            const x = PAD_L + i*bw + bw/2, y = ay(d.cloud_base);
            if(!started){ ctx.moveTo(x, y); started = true; } else ctx.lineTo(x, y);
        });
        ctx.stroke();

        // ligne décollage
        const yd = ay(decollage);
        ctx.beginPath(); ctx.strokeStyle='rgba(255,255,255,0.42)'; ctx.lineWidth=1.4; ctx.setLineDash([4,3]);
        ctx.moveTo(PAD_L, yd); ctx.lineTo(PAD_L+CW, yd); ctx.stroke(); ctx.setLineDash([]);

        // flèches direction + bande axe + axe temps (en HTML sous le canvas)
        const dirStrip  = document.getElementById('rp2-dir-strip');
        const axisStrip = document.getElementById('rp2-axis-strip');
        const wax       = document.getElementById('rp2-wax');
        if(dirStrip){
            dirStrip.innerHTML = '';
            data.forEach(d => {
                const cell = document.createElement('div'); cell.className = 'dir-arrow';
                if(d.wind_dir != null){
                    const ok = app._inAxis(d.wind_dir);
                    const col = ok ? '#4ea8e0' : 'rgba(239,68,68,0.65)';
                    const rot = d.wind_dir + 180; // pointe où le vent VA
                    cell.innerHTML = `<i class="ti ti-arrow-up" aria-hidden="true" style="font-size:9px;color:${col};transform:rotate(${rot}deg);display:block"></i>`;
                }
                dirStrip.appendChild(cell);
            });
        }
        if(axisStrip){
            axisStrip.innerHTML = '';
            data.forEach(d => {
                const c = document.createElement('div'); c.className = 'asc';
                c.style.background = (d.wind_dir != null && app._inAxis(d.wind_dir)) ? 'rgba(74,222,128,0.28)' : 'rgba(239,68,68,0.22)';
                axisStrip.appendChild(c);
            });
        }
        if(wax){
            wax.innerHTML = '';
            data.forEach((d, i) => { if(i % 3 === 0){ const s=document.createElement('span'); s.textContent=parseInt(d.hour,10)+'h'; wax.appendChild(s); } });
        }

        // géométrie mémorisée pour le tooltip
        WIND = { data, W, CW, bw, decollage, inAxis: d => app._inAxis(d) };

        // ── Tooltip (handlers assignés → pas d'empilement au re-render) ──
        canvas.onmousemove  = e => showWindTT(e.clientX);
        canvas.onmouseleave = () => hideWindTT();
        canvas.ontouchmove  = e => { e.preventDefault(); showWindTT(e.touches[0].clientX); };
        canvas.ontouchend   = () => hideWindTT();
    };

    function showWindTT(clientX){
        const g = WIND; if(!g) return;
        const canvas = document.getElementById('rp2-wind-cv'); if(!canvas) return;
        const rect = canvas.getBoundingClientRect();
        const x = clientX - rect.left;
        const idx = Math.max(0, Math.min(g.data.length-1, Math.floor((x*g.W/rect.width - PAD_L)/g.bw)));
        const d = g.data[idx];
        const ok = d.wind_dir != null && g.inAxis(d.wind_dir);
        const margin = d.cloud_base != null ? d.cloud_base - g.decollage : null;

        const set = (id, txt, col) => { const el = document.getElementById(id); if(el){ el.textContent = txt; if(col) el.style.color = col; } };
        set('rp2-tt-hour', parseInt(d.hour,10)+'h00');
        set('rp2-tt-mean', d.wind_avg != null ? Math.round(d.wind_avg)+' km/h' : '—', d.wind_avg != null ? spdCol(d.wind_avg) : '#e8f4fd');
        set('rp2-tt-gust', d.wind_max != null ? Math.round(d.wind_max)+' km/h' : '—');
        set('rp2-tt-dir',  d.wind_dir != null ? (Math.round(d.wind_dir)+'° '+degToCompass(d.wind_dir)+(ok?' ✓':' ✗')) : '—', d.wind_dir == null ? '#e8f4fd' : (ok ? '#4ade80' : '#f87171'));
        set('rp2-tt-ceil', d.cloud_base != null ? d.cloud_base.toLocaleString('fr-FR')+' m' : '—');
        set('rp2-tt-deco', Math.round(g.decollage).toLocaleString('fr-FR')+' m');
        set('rp2-tt-margin', margin != null ? (margin>=0?'+':'')+Math.round(margin).toLocaleString('fr-FR')+' m' : '—', margin != null ? mrgCol(margin) : '#e8f4fd');

        const xPx = (PAD_L + idx*g.bw + g.bw/2) / g.W * rect.width;
        const hl = document.getElementById('rp2-hover-line');
        if(hl){ hl.style.display='block'; hl.style.left=xPx+'px'; }
        const tt = document.getElementById('rp2-wind-tt');
        if(tt){
            tt.style.display='block';
            const ttW = 145;
            let left = xPx + 8;
            if(left + ttW > rect.width - PAD_R) left = xPx - ttW - 8;
            tt.style.left = Math.max(0, left)+'px';
            tt.style.top = '2px';
        }
    }
    function hideWindTT(){
        const tt = document.getElementById('rp2-wind-tt'); if(tt) tt.style.display='none';
        const hl = document.getElementById('rp2-hover-line'); if(hl) hl.style.display='none';
    }
})();
