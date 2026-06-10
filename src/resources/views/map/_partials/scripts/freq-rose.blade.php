// ── Rose des vents fréquentielle (popups balise & station) ───
// Densité directionnelle (36 secteurs × 5 anneaux de vitesse) + vecteur
// du relevé actuel. Pas de secteur d'axe ni de verdict (décision refonte).
// Rendu sur le canvas #fpop-rose-cv ; légende dégradé sur #fpop-grad-cv.
// readings : [{dir, spd}] ; current : {dir, mean, gust} (champs nullables).

(function(){
    const CX = 100, CY = 100, MAX_R = 88, SZ = 200;
    const SECTORS = 36, RINGS = 5;
    const RAMP = [[78,168,224],[74,222,128],[251,191,36],[249,115,22],[239,68,68]];

    function polar(deg, r){ const rad = (deg-90)*Math.PI/180; return { x:CX+Math.cos(rad)*r, y:CY+Math.sin(rad)*r }; }
    function spdCol(s){ if(s == null) return '#9ca3af'; return s < 12 ? '#4ade80' : s < 22 ? '#4ea8e0' : s < 32 ? '#fbbf24' : '#f87171'; }

    window.drawFreqRose = function(readings, current){
        const cv = document.getElementById('fpop-rose-cv');
        if(!cv) return;
        const dpr = window.devicePixelRatio || 1;
        cv.width = Math.round(SZ*dpr); cv.height = Math.round(SZ*dpr);
        cv.style.width = SZ+'px'; cv.style.height = SZ+'px';
        const ctx = cv.getContext('2d'); ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, SZ, SZ);

        const ringR = MAX_R / RINGS;
        // Échelle dynamique : pic de vent (relevés + actuel + rafales) arrondi
        // au multiple de 5 supérieur, plancher à 25 km/h. Un anneau = scale/5.
        const speeds = [];
        (readings || []).forEach(r => { if(r.spd != null) speeds.push(r.spd); });
        if(current){ if(current.mean != null) speeds.push(current.mean); if(current.gust != null) speeds.push(current.gust); }
        const peak = speeds.length ? Math.max(...speeds) : 40;
        const maxScale = Math.max(25, Math.ceil(peak/5)*5);
        const band = maxScale / RINGS;

        const grid = Array.from({length:SECTORS}, () => new Array(RINGS).fill(0));
        (readings || []).forEach(({dir, spd}) => {
            if(dir == null || spd == null) return;
            const si = Math.floor((((dir+5)%360)+360)%360 / 10) % SECTORS;
            const ri = Math.min(RINGS-1, Math.max(0, Math.floor(spd/band)));
            grid[si][ri]++;
        });
        const max = Math.max(...grid.map(s => s.reduce((a,b)=>a+b,0)), 1);

        // rings de référence
        for(let i = 1; i <= RINGS; i++){
            ctx.beginPath(); ctx.arc(CX, CY, i*ringR, 0, Math.PI*2);
            ctx.strokeStyle = 'rgba(255,255,255,0.06)'; ctx.lineWidth = 0.5; ctx.stroke();
        }
        // axes cardinaux
        [0,90,180,270].forEach(deg => {
            const p = polar(deg, MAX_R+12);
            ctx.beginPath(); ctx.moveTo(CX, CY); ctx.lineTo(p.x, p.y);
            ctx.strokeStyle = 'rgba(255,255,255,0.05)'; ctx.lineWidth = 0.5; ctx.stroke();
        });

        // cellules fréquence
        const sa = 2*Math.PI/SECTORS;
        for(let si = 0; si < SECTORS; si++){
            const startA = (si/SECTORS)*2*Math.PI - Math.PI/2 - sa/2;
            const endA = startA + sa;
            for(let ri = 0; ri < RINGS; ri++){
                const count = grid[si][ri];
                if(!count) continue;
                const [r,g,b] = RAMP[ri];
                const alpha = (0.08 + (count/max)*0.72).toFixed(2);
                ctx.beginPath();
                ctx.arc(CX, CY, (ri+1)*ringR, startA, endA);
                ctx.arc(CX, CY, ri*ringR, endA, startA, true);
                ctx.closePath();
                ctx.fillStyle = `rgba(${r},${g},${b},${alpha})`;
                ctx.fill();
            }
        }

        // labels cardinaux
        [['N',0],['E',90],['S',180],['O',270],['NE',45],['SE',135],['SO',225],['NO',315]].forEach(([lbl,deg]) => {
            const dist = deg%90===0 ? MAX_R+16 : MAX_R+12;
            const p = polar(deg, dist);
            ctx.fillStyle = deg%90===0 ? 'rgba(255,255,255,0.55)' : 'rgba(255,255,255,0.32)';
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.font = (deg%90===0 ? 'bold ' : '') + '9px sans-serif';
            ctx.fillText(lbl, p.x, p.y);
        });
        // labels vitesse
        ctx.font = '8px sans-serif'; ctx.fillStyle = 'rgba(255,255,255,0.4)'; ctx.textAlign = 'left';
        [1,2,3,4].forEach(i => ctx.fillText(Math.round(i*band)+'km', CX+3, CY-i*ringR+4));

        // vecteur relevé actuel
        if(current && current.dir != null && current.mean != null){
            const r = Math.round((current.mean/maxScale)*MAX_R);
            const tip = polar(current.dir, r);
            const col = spdCol(current.mean);
            const ang = (current.dir-90)*Math.PI/180;
            if(current.gust != null){
                const gr = Math.round(Math.min(1, current.gust/maxScale)*MAX_R);
                ctx.beginPath(); ctx.arc(tip.x, tip.y, Math.max(3, gr-r), 0, Math.PI*2);
                ctx.strokeStyle = 'rgba(78,168,224,0.28)'; ctx.lineWidth = 1.5; ctx.stroke();
            }
            ctx.beginPath(); ctx.moveTo(CX, CY); ctx.lineTo(tip.x, tip.y);
            ctx.strokeStyle = col; ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.stroke();
            const hl = 10, hw = 5, bx = tip.x-Math.cos(ang)*hl, by = tip.y-Math.sin(ang)*hl;
            ctx.beginPath(); ctx.moveTo(tip.x, tip.y);
            ctx.lineTo(bx+Math.cos(ang+Math.PI/2)*hw, by+Math.sin(ang+Math.PI/2)*hw);
            ctx.lineTo(bx+Math.cos(ang-Math.PI/2)*hw, by+Math.sin(ang-Math.PI/2)*hw);
            ctx.closePath(); ctx.fillStyle = col; ctx.fill();
            ctx.beginPath(); ctx.arc(CX, CY, 5, 0, Math.PI*2); ctx.fillStyle = col; ctx.fill();
        } else {
            ctx.beginPath(); ctx.arc(CX, CY, 4, 0, Math.PI*2); ctx.fillStyle = '#9ca3af'; ctx.fill();
        }

        // légende dégradé Rare → Fréquent
        const gc = document.getElementById('fpop-grad-cv');
        if(gc){
            const gW = Math.max((gc.parentElement.offsetWidth || 200) - 80, 100);
            gc.width = Math.round(gW*dpr); gc.height = Math.round(6*dpr);
            gc.style.width = gW+'px'; gc.style.height = '6px';
            const gctx = gc.getContext('2d'); gctx.scale(dpr, dpr);
            const gg = gctx.createLinearGradient(0, 0, gW, 0);
            gg.addColorStop(0, 'rgba(78,168,224,0.15)');
            gg.addColorStop(0.3, 'rgba(74,222,128,0.5)');
            gg.addColorStop(0.6, 'rgba(251,191,36,0.7)');
            gg.addColorStop(1, 'rgba(239,68,68,0.85)');
            gctx.fillStyle = gg; gctx.fillRect(0, 0, gW, 6);
        }
    };

    // ── Mini-histogramme 12 h (onglet Mesures, stations) ─────────────────
    window.drawMiniHist = function(readings){
        const cv = document.getElementById('fpop-mhist-cv'); if(!cv) return;
        const dpr = window.devicePixelRatio || 1, H = 70;
        const W = cv.parentElement.offsetWidth || 320;
        cv.width = Math.round(W*dpr); cv.height = Math.round(H*dpr);
        cv.style.width = W+'px'; cv.style.height = H+'px';
        const ctx = cv.getContext('2d'); ctx.scale(dpr, dpr);
        ctx.clearRect(0, 0, W, H);

        const now = Date.now();
        const vals = (readings || [])
            .filter(r => r.wind_speed_avg != null)
            .filter(r => { const t = new Date(r.observed_at || r.read_at).getTime(); return now - t <= 12.5*3600*1000; })
            .map(r => +r.wind_speed_avg);
        if(!vals.length) return;

        const mx = Math.max(...vals, 1), mn = Math.min(...vals, 0), span = (mx - mn) || 1;
        const PL = 26, PR = 4, PT = 4, PB = 14, CW = W-PL-PR, CH = H-PT-PB;
        [Math.round(mn), Math.round((mn+mx)/2), Math.round(mx)].forEach(v => {
            const y = PT + CH - ((v-mn)/span)*CH;
            ctx.strokeStyle = 'rgba(255,255,255,0.05)'; ctx.lineWidth = 0.5;
            ctx.beginPath(); ctx.moveTo(PL, y); ctx.lineTo(PL+CW, y); ctx.stroke();
            ctx.fillStyle = 'rgba(255,255,255,0.42)'; ctx.font = '9px sans-serif'; ctx.textAlign = 'right';
            ctx.fillText(v, PL-3, y+3);
        });
        const bw = CW / vals.length;
        vals.forEach((v, i) => {
            const bh = ((v-mn)/span)*CH;
            ctx.fillStyle = i === vals.length-1 ? '#4ea8e0' : spdCol(v);
            ctx.globalAlpha = i === vals.length-1 ? 1 : 0.7;
            ctx.fillRect(PL + i*bw + 0.5, PT+CH-bh, Math.max(1, bw-1), bh);
        });
        ctx.globalAlpha = 1;
    };
})();
