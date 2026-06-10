// ── Panneau droit v2 — Rose des vents animée (modale) ────────
// Ouverte depuis la mini-rose de l'onglet Synthèse. Rendu SVG impératif
// (par id) piloté par l'app Alpine : données = app.synthDayData (jour
// sélectionné, fenêtre solaire), axe favorable + altitude = selectedFeature.
// Lecture auto (650 ms/heure) gérée côté app (wrTogglePlay/_wrTimer).

(function(){
    const CX = 115, CY = 115, MAX_R = 98, MAX_SPD = 50;
    const DIR_CARDS = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSO','SO','OSO','O','ONO','NO','NNO'];
    const SVGNS = 'http://www.w3.org/2000/svg';

    function polar(deg, r){
        const rad = (deg - 90) * Math.PI / 180;
        return { x:+(CX + Math.cos(rad)*r).toFixed(2), y:+(CY + Math.sin(rad)*r).toFixed(2) };
    }
    function spdToR(spd){ return spd == null ? 0 : Math.round((spd/MAX_SPD)*MAX_R); }
    function dirCard(deg){ return DIR_CARDS[Math.round(deg/22.5)%16]; }
    function spdColor(spd){ if(spd == null) return '#9ca3af'; if(spd < 12) return '#4ade80'; if(spd < 22) return '#4ea8e0'; if(spd < 32) return '#fbbf24'; return '#f87171'; }
    function inAxis(deg, cfg){
        if(deg == null || cfg.axisFrom == null || cfg.axisTo == null) return false;
        const d = ((deg%360)+360)%360, a = cfg.axisFrom, b = cfg.axisTo;
        return a <= b ? (d >= a && d <= b) : (d >= a || d <= b);
    }
    function $(id){ return document.getElementById(id); }

    function drawAxisSector(cfg){
        const el = $('wr-axis-sector'); if(!el) return;
        if(cfg.axisFrom == null || cfg.axisTo == null){ el.setAttribute('d', ''); return; }
        const r = MAX_R + 10;
        const a1 = (cfg.axisFrom - 90)*Math.PI/180, a2 = (cfg.axisTo - 90)*Math.PI/180;
        const p1 = { x:+(CX+Math.cos(a1)*r).toFixed(2), y:+(CY+Math.sin(a1)*r).toFixed(2) };
        const p2 = { x:+(CX+Math.cos(a2)*r).toFixed(2), y:+(CY+Math.sin(a2)*r).toFixed(2) };
        const span = ((cfg.axisTo - cfg.axisFrom)+360)%360;
        const large = span > 180 ? 1 : 0;
        el.setAttribute('d', `M${CX},${CY} L${p1.x},${p1.y} A${r},${r} 0 ${large},1 ${p2.x},${p2.y} Z`);
    }

    function drawTrail(data, idx){
        const g = $('wr-trail'); if(!g) return;
        g.innerHTML = '';
        const trailLen = Math.min(6, idx);
        const start = Math.max(0, idx - trailLen);
        for(let i = start; i < idx; i++){
            const d = data[i]; if(d.mean == null || d.dir == null) continue;
            const p = polar(d.dir, spdToR(d.mean));
            const age = idx - i;
            const alpha = (0.05 + (trailLen - age)/trailLen*0.22).toFixed(2);
            const c = document.createElementNS(SVGNS, 'circle');
            c.setAttribute('cx', p.x); c.setAttribute('cy', p.y); c.setAttribute('r', 3);
            c.setAttribute('fill', `rgba(78,168,224,${alpha})`);
            g.appendChild(c);
            const prev = data[i-1];
            if(i > start && prev.mean != null && prev.dir != null){
                const pp = polar(prev.dir, spdToR(prev.mean));
                const ln = document.createElementNS(SVGNS, 'line');
                ln.setAttribute('x1', pp.x); ln.setAttribute('y1', pp.y);
                ln.setAttribute('x2', p.x); ln.setAttribute('y2', p.y);
                ln.setAttribute('stroke', `rgba(78,168,224,${(alpha*0.5).toFixed(2)})`);
                ln.setAttribute('stroke-width', '1');
                g.appendChild(ln);
            }
        }
    }

    function drawArrow(d){
        const shaft = $('wr-arrow-line'), head = $('wr-arrow-head'), ring = $('wr-gust-ring'), center = $('wr-center');
        if(!shaft) return;
        if(d.mean == null || d.dir == null){
            shaft.setAttribute('x2', CX); shaft.setAttribute('y2', CY);
            head.setAttribute('points', `${CX},${CY}`);
            ring.setAttribute('r', 0);
            center.setAttribute('fill', '#9ca3af');
            return;
        }
        const r = spdToR(d.mean), tip = polar(d.dir, r), col = spdColor(d.mean);
        shaft.setAttribute('x2', tip.x); shaft.setAttribute('y2', tip.y); shaft.setAttribute('stroke', col);
        const ang = (d.dir - 90)*Math.PI/180, hLen = 10, hW = 5;
        const bx = tip.x - Math.cos(ang)*hLen, by = tip.y - Math.sin(ang)*hLen;
        const lx = +(bx + Math.cos(ang+Math.PI/2)*hW).toFixed(2), ly = +(by + Math.sin(ang+Math.PI/2)*hW).toFixed(2);
        const rx = +(bx + Math.cos(ang-Math.PI/2)*hW).toFixed(2), ry = +(by + Math.sin(ang-Math.PI/2)*hW).toFixed(2);
        head.setAttribute('points', `${tip.x},${tip.y} ${lx},${ly} ${rx},${ry}`);
        head.setAttribute('fill', col);
        const gr = spdToR(d.gust);
        ring.setAttribute('cx', tip.x); ring.setAttribute('cy', tip.y);
        ring.setAttribute('r', d.gust == null ? 0 : Math.max(3, gr - r));
        center.setAttribute('fill', col);
    }

    function updateStats(app, d){
        const cfg = app._wrConfig;
        const ok = inAxis(d.dir, cfg);
        const col = spdColor(d.mean), gc = spdColor(d.gust);
        const set = (id, txt, color) => { const el = $(id); if(el){ el.textContent = txt; if(color) el.style.color = color; } };

        set('stat-dir-val', d.dir != null ? Math.round(d.dir)+'°' : '—', '#e8f4fd');
        set('stat-dir-card', d.dir != null ? ' '+dirCard(d.dir) : '');
        set('stat-dir-axe', d.dir == null ? '—' : (ok ? 'Dans l\'axe du site' : 'Hors axe du site'), d.dir == null ? '#9ca3af' : (ok ? '#4ade80' : '#f87171'));

        set('stat-spd-val', d.mean != null ? Math.round(d.mean) : '—', col);
        const bs = $('bar-spd'); if(bs){ bs.style.width = (d.mean != null ? Math.round(d.mean/MAX_SPD*100) : 0)+'%'; bs.style.background = col; }

        set('stat-gust-val', d.gust != null ? Math.round(d.gust) : '—', gc);
        const bg = $('bar-gust'); if(bg){ bg.style.width = (d.gust != null ? Math.round(d.gust/MAX_SPD*100) : 0)+'%'; bg.style.background = gc; }

        set('stat-ceil-val', d.ceil != null ? d.ceil.toLocaleString('fr-FR') : '—', '#4ea8e0');
        const margin = (d.ceil != null) ? d.ceil - (cfg.altitude||0) : null;
        set('stat-ceil-sub', margin != null ? ((margin>=0?'+':'')+margin.toLocaleString('fr-FR')+' m / décollage') : '— m / décollage');

        set('wr-time-lbl', d.hour+'h');

        const vdot = $('wr-verdict-dot'), vtxt = $('wr-verdict-text');
        if(vdot && vtxt){
            if(d.dir == null || d.mean == null){
                vdot.style.background = '#9ca3af'; vtxt.innerHTML = '<strong>Donnée indisponible</strong>';
            } else if(!ok){
                vdot.style.background = '#dc2626'; vtxt.innerHTML = 'Vent hors axe — <strong>conditions défavorables</strong>';
            } else if(d.gust != null && d.gust >= 35){
                vdot.style.background = '#d97706'; vtxt.innerHTML = 'Rafales élevées — <strong>à surveiller avant de décoller</strong>';
            } else if(d.mean < 8){
                vdot.style.background = '#4ea8e0'; vtxt.innerHTML = 'Vent faible — <strong>thermiques possibles en journée</strong>';
            } else {
                vdot.style.background = '#16a34a'; vtxt.innerHTML = 'Vent dans l\'axe — <strong>conditions favorables pour voler</strong>';
            }
        }
    }

    function buildTimeline(app){
        const data = app._wrData, idx = app.wrIdx;
        const track = $('wr-tl-track'), dir = $('wr-tl-dir'), hoursEl = $('wr-tl-hours'), range = $('wr-tl-range');
        if(!track) return;
        track.innerHTML = ''; dir.innerHTML = ''; if(hoursEl) hoursEl.innerHTML = '';
        const maxGust = Math.max(1, ...data.map(d => d.gust ?? 0));

        data.forEach((d, i) => {
            const col = document.createElement('div');
            col.className = 'wr-tl-col' + (i === idx ? ' active' : '');
            const gustH = Math.round(4 + ((d.gust ?? 0)/maxGust)*26);
            const meanH = Math.round(4 + ((d.mean ?? 0)/maxGust)*26);
            const dH = Math.max(0, gustH - meanH);
            const gustSeg = document.createElement('div');
            gustSeg.className = 'wr-tl-gust';
            gustSeg.style.cssText = `height:${dH}px;background:rgba(251,191,36,0.6);border-radius:1px 1px 0 0;`;
            const meanSeg = document.createElement('div');
            meanSeg.className = 'wr-tl-mean';
            meanSeg.style.cssText = `height:${meanH}px;background:${spdColor(d.mean)};opacity:${i === idx ? 1 : 0.55};`;
            col.appendChild(gustSeg); col.appendChild(meanSeg);
            col.addEventListener('click', () => app.wrSetIdx(i));
            track.appendChild(col);

            const dc = document.createElement('div');
            dc.className = 'wr-tl-dir-cell';
            dc.style.background = inAxis(d.dir, app._wrConfig) ? 'rgba(74,222,128,0.32)' : 'rgba(239,68,68,0.28)';
            dir.appendChild(dc);
        });

        // libellés d'heures : ~5 graduations
        if(hoursEl && data.length){
            const n = data.length;
            const idxs = [...new Set([0, Math.round(n*0.25), Math.round(n*0.5), Math.round(n*0.75), n-1])];
            idxs.forEach(i => { const s = document.createElement('span'); s.textContent = data[i].hour+'h'; hoursEl.appendChild(s); });
        }
        if(range && data.length) range.textContent = data[0].hour+'h – '+data[data.length-1].hour+'h';
    }

    // ── API exposée à l'app ──────────────────────────────────────────────
    window.wrInit = function(app){
        const data = app.synthDayData.map(h => ({
            hour: parseInt(h.hour, 10),
            mean: h.wind_avg, gust: h.wind_max, dir: h.wind_dir, ceil: h.cloud_base,
        }));
        app._wrData = data;
        app._wrConfig = {
            altitude: app.selectedFeature?.altitude ?? 0,
            axisFrom: app.selectedFeature?.wind_dir_min,
            axisTo:   app.selectedFeature?.wind_dir_max,
        };
        // créneau de départ : le plus proche de 13h
        let idx = 0, bd = 99;
        data.forEach((d, i) => { const x = Math.abs(d.hour - 13); if(x < bd){ bd = x; idx = i; } });
        app.wrIdx = Math.min(idx, Math.max(0, data.length - 1));
        drawAxisSector(app._wrConfig);
        window.wrRender(app);
        app.wrTogglePlay(); // lecture auto au démarrage
    };

    window.wrRender = function(app){
        const data = app._wrData;
        if(!data || !data.length) return;
        const d = data[app.wrIdx]; if(!d) return;
        drawTrail(data, app.wrIdx);
        drawArrow(d);
        updateStats(app, d);
        buildTimeline(app);
    };
})();
