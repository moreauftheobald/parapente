// ── Panneau droit v2 — onglet « Scoring » ────────────────────
// Deux grilles de barres canvas « style B » (segments tricolores avec
// transition floue aux jonctions) :
//   - Volabilité : données réelles (allScores[id] → detail[param].color
//     + status), granularité horaire (fenêtre solaire), 1j ou 5j.
//   - Qualité de vol : STUB (quality_detail pas encore peuplé/exposé —
//     cf. plan refonte) : barres neutres + badge « à venir ».
// Toggle Global/perso : lit color/status (perso) ou color_global/
// status_global (global) — déjà présents dans le payload, sans re-fetch.

(function(){
    const COL = { green:'#16a34a', orange:'#d97706', red:'#dc2626', na:'#3a4654' };
    const VOLA_PARAMS = [
        {key:'wind_dir',   label:'Direction'},
        {key:'wind_speed', label:'Vitesse'},
        {key:'wind_gust',  label:'Rafales'},
        {key:'precip',     label:'Précip.'},
        {key:'cloud_base', label:'Plafond'},
        {key:'status',     label:'Global'},
    ];
    const QUAL_PARAMS = [
        {label:'Thermiques'}, {label:'Turbulences'}, {label:'Cisaillement'},
        {label:'Humidité'}, {label:'Global'},
    ];

    // Couleur d'un créneau pour un paramètre, selon le mode (perso/global).
    function scoreColor(score, key, mode){
        if(!score) return 'na';
        if(key === 'status' || key === 'Global'){
            const v = mode === 'perso' ? score.status : (score.status_global ?? score.status);
            return ['green','orange','red'].includes(v) ? v : 'na';
        }
        const d = score.detail?.[key];
        if(!d) return 'na';
        const v = mode === 'perso' ? d.color : (d.color_global ?? d.color);
        return ['green','orange','red'].includes(v) ? v : 'na';
    }

    // Reconstruit les heures (fenêtre solaire) + index par heure d'un jour.
    function dayHours(app, raw){
        const id = app.site.id;
        const win = (app.sunWindows[id] || {})[raw];
        const dayScores = (app.allScores[id] || []).filter(s => s.day === raw);
        let hours;
        if(win && win.start_hour != null && win.end_hour != null){
            hours = []; for(let h = win.start_hour; h <= win.end_hour; h++) hours.push(h);
        } else {
            hours = [...new Set(dayScores.map(s => parseInt(s.hour, 10)))].sort((a,b)=>a-b);
        }
        const byHour = {};
        dayScores.forEach(s => { byHour[parseInt(s.hour, 10)] = s; });
        return { hours, byHour };
    }

    function colorsForLayout(layout, key, mode){
        const out = [];
        layout.days.forEach(d => d.hours.forEach(h => out.push(scoreColor(d.byHour[h], key, mode))));
        return out;
    }

    // ── Barre « style B » : segments tricolores + blend ~50% aux jonctions.
    function drawScoreBar(cv, colors, H, isPerso){
        const dpr = window.devicePixelRatio || 1;
        const W = Math.max(cv.parentElement.offsetWidth || 200, 10);
        cv.width = Math.round(W*dpr); cv.height = Math.round(H*dpr);
        cv.style.width = W+'px'; cv.style.height = H+'px';
        const ctx = cv.getContext('2d'); ctx.scale(dpr, dpr);
        const n = colors.length; if(!n) return;
        const sw = W/n, bl = sw*0.5;
        colors.forEach((c, i) => {
            ctx.fillStyle = COL[c] || COL.na; ctx.fillRect(i*sw, 0, sw+0.5, H);
            const nx = colors[i+1];
            if(nx !== undefined && nx !== c){
                const g = ctx.createLinearGradient(i*sw+sw-bl, 0, i*sw+sw+bl, 0);
                g.addColorStop(0, COL[c] || COL.na); g.addColorStop(1, COL[nx] || COL.na);
                ctx.fillStyle = g; ctx.fillRect(i*sw+sw-bl, 0, bl*2, H);
            }
        });
        if(isPerso){ ctx.fillStyle = 'rgba(167,139,250,0.1)'; ctx.fillRect(0, 0, W, H); }
    }

    // ── Une grille (Volabilité ou Qualité) : header + lignes + axe temps.
    function buildSection(app, container, title, icon, params, layout, mode, opts){
        container.innerHTML = '';
        const isPerso = mode === 'perso' && !opts.stub;

        const th = document.createElement('div'); th.className = 'sgh';
        th.innerHTML = `<i class="ti ${icon}" aria-hidden="true"></i>${title}`;
        const badge = document.createElement('div');
        if(opts.stub){
            badge.className = 'sc-soon'; badge.textContent = 'à venir';
        } else {
            badge.className = 'sgh-badge';
            const gl = colorsForLayout(layout, 'status', mode);
            badge.textContent = gl.filter(c => c === 'green').length + 'h ' + opts.badgeSuffix;
        }
        th.appendChild(badge); container.appendChild(th);

        params.forEach(p => {
            const isG = p.label === 'Global';
            const H = isG ? 20 : 14;
            const row = document.createElement('div'); row.className = 'prow';
            const lbl = document.createElement('div'); lbl.className = isG ? 'plbl gb' : 'plbl';
            if(isPerso) lbl.style.color = isG ? '#a78bfa' : 'rgba(167,139,250,0.6)';
            lbl.textContent = p.label; row.appendChild(lbl);
            const tr = document.createElement('div'); tr.className = 'track'; tr.style.height = H+'px';
            const cv = document.createElement('canvas'); tr.appendChild(cv); row.appendChild(tr);
            container.appendChild(row);
            const colors = opts.stub
                ? new Array(Math.max(layout.totalHours, 1)).fill('na')
                : colorsForLayout(layout, p.key, mode);
            requestAnimationFrame(() => drawScoreBar(cv, colors, H, isPerso));
        });

        // axe temps
        const tax = document.createElement('div'); tax.className = 'sc-tax';
        if(app.scZoom === '1j'){
            const hrs = layout.days[0]?.hours || [];
            hrs.forEach((h, i) => {
                const el = document.createElement('div'); el.className = 'sctick';
                el.style.flex = '1'; el.textContent = (i % 3 === 0) ? h+'h' : '';
                tax.appendChild(el);
            });
        } else {
            layout.days.forEach(d => {
                const el = document.createElement('div'); el.className = 'sctick';
                el.style.flex = String(Math.max(d.hours.length, 1));
                el.textContent = (d.label || d.raw).split(' ')[0];
                tax.appendChild(el);
            });
        }
        container.appendChild(tax);
    }

    // ── Bandeau de cards par jour (mode 5j) ──────────────────────────────
    function buildDayStrip(app, mode){
        const wrap = document.getElementById('rp2-day-strip');
        if(!wrap) return;
        wrap.innerHTML = '';
        if(app.scZoom !== '5j') return;
        const strip = document.createElement('div'); strip.className = 'day-strip';
        app.days.slice(0, 5).forEach((d, i) => {
            const { hours, byHour } = dayHours(app, d.raw);
            const colors = hours.map(h => scoreColor(byHour[h], 'status', mode));
            const greens = colors.filter(c => c === 'green').length;
            const pct = colors.length ? Math.round(greens/colors.length*100) : 0;
            const c = pct >= 70 ? '#16a34a' : pct >= 45 ? '#d97706' : '#dc2626';
            const card = document.createElement('div'); card.className = 'dsc' + (i === app.selectedDayIdx ? ' on' : '');
            card.innerHTML = `<div class="dsc-d">${(d.label || d.raw).split(' ')[0]}</div>`
                + `<div class="dsc-b" style="background:${c};width:${pct}%"></div>`
                + `<div class="dsc-s" style="color:${c}">${pct}</div>`;
            card.onclick = () => { app.selectDay(i); app.setScZoom('1j'); };
            strip.appendChild(card);
        });
        wrap.appendChild(strip);
    }

    // ── Orchestrateur : (re)construit tout l'onglet Scoring. ─────────────
    window.buildScoringTab = function(app){
        const id = app.site?.id;
        const volaEl = document.getElementById('rp2-sc-vola');
        const qualEl = document.getElementById('rp2-sc-qual');
        const stripEl = document.getElementById('rp2-day-strip');
        if(!volaEl || !qualEl) return;

        const scores = app.allScores[id] || [];
        if(!scores.length){
            volaEl.innerHTML = '<div class="rp-placeholder" style="padding:30px 16px;text-align:center;">Aucune donnée de scoring disponible.</div>';
            qualEl.innerHTML = ''; if(stripEl) stripEl.innerHTML = '';
            return;
        }

        const mode = app.scoringHasPerso ? app.scMode : 'global';
        const dayList = app.scZoom === '1j' ? [app.days[app.selectedDayIdx]] : app.days.slice(0, 5);
        const layout = { days: [], totalHours: 0 };
        dayList.forEach(d => {
            if(!d) return;
            const { hours, byHour } = dayHours(app, d.raw);
            layout.days.push({ raw:d.raw, label:d.label, hours, byHour });
            layout.totalHours += hours.length;
        });

        buildDayStrip(app, mode);
        buildSection(app, volaEl, 'Volabilité', 'ti-plane', VOLA_PARAMS, layout, mode, { badgeSuffix:'de vol' });
        buildSection(app, qualEl, 'Qualité de vol', 'ti-star', QUAL_PARAMS, layout, mode, { stub:true });
    };
})();
