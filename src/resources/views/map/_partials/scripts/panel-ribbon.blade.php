// ── Panneau droit v2 — onglet « Modèles » (Consensus Ribbon) ─
// Variables exposées (tabs) + rendu Chart.js du ribbon multi-modèles.
// Source : /api/sites/{id}/multimodel (1j) ou fusion 5 jours (loadFiveDays).
// Le consensus tracé est celui du serveur (consensus[h][ckey]) — masquer un
// modèle dim son ruban et met à jour l'enveloppe/divergence, mais ne
// recalcule pas le consensus côté client (pas de poids ni de moyenne
// circulaire fiables ici). Choix assumé, cf. plan refonte.

// Catalogue des variables du ribbon (mkey = clé dans data[h][model],
// ckey = clé dans consensus[h]). Exposé à Alpine via mapApp().MOD_VARS.
const MOD_VARS = [
    {key:'speed',  label:'Vent moyen', icon:'ti-wind',         unit:'km/h', mkey:'wind_avg',   ckey:'wind_speed'},
    {key:'gust',   label:'Rafales',    icon:'ti-bolt',         unit:'km/h', mkey:'wind_max',   ckey:'wind_gust'},
    {key:'dir',    label:'Direction',  icon:'ti-compass',      unit:'°',    mkey:'wind_dir',   ckey:'wind_dir', wrap:true},
    {key:'ceil',   label:'Plafond',    icon:'ti-arrow-bar-up', unit:'m',    mkey:'cloud_base', ckey:'cloud_base'},
    {key:'precip', label:'Précip.',    icon:'ti-droplet',      unit:'mm/h', mkey:'precip',     ckey:'precip'},
];

(function(){
    let CHART = null;

    function divColor(r){ return r < .3 ? 'rgba(74,222,128,0.5)' : r < .6 ? 'rgba(251,191,36,0.5)' : 'rgba(239,68,68,0.5)'; }

    function formatHourLabel(app, payload, i){
        const hours = payload.hours || [];
        const h = hours[i];
        if(h == null) return '';
        if(app.modZoom === '5j'){
            const di = Math.floor(i/24);
            const lbl = (payload.day_labels?.[di]?.label) || '';
            return (lbl ? lbl + ' ' : '') + (h % 24) + 'h';
        }
        return h + 'h';
    }

    function buildDivBar(app, payload, visible){
        const bar = document.getElementById('rp2-mod-divbar'); if(!bar) return;
        bar.innerHTML = '';
        const hours = payload.hours || [];
        const mkey = app.modVarObj.mkey;
        const divs = hours.map(h => {
            const vals = visible.map(m => { const v = payload.data?.[h]?.[m.id]?.[mkey]; return v == null ? null : +v; }).filter(v => v != null);
            if(vals.length < 2) return 0;
            const mean = vals.reduce((a,b)=>a+b,0)/vals.length;
            return Math.sqrt(vals.reduce((a,b)=>a+(b-mean)**2,0)/vals.length);
        });
        const mx = Math.max(...divs, 0.01);
        const groups  = app.modZoom === '5j' ? Math.ceil(hours.length/24) : hours.length;
        const perGrp  = app.modZoom === '5j' ? 24 : 1;
        for(let g = 0; g < groups; g++){
            const chunk = divs.slice(g*perGrp, (g+1)*perGrp);
            const avg = chunk.length ? chunk.reduce((a,b)=>a+b,0)/chunk.length : 0;
            const seg = document.createElement('div'); seg.className = 'div-seg';
            seg.style.background = divColor(avg/mx);
            bar.appendChild(seg);
        }
    }

    function buildModTax(app, payload){
        const tax = document.getElementById('rp2-mod-tax'); if(!tax) return;
        tax.innerHTML = '';
        const hours = payload.hours || [];
        if(app.modZoom === '1j'){
            hours.forEach((h, i) => {
                const el = document.createElement('div'); el.className = 'mod-tick';
                el.textContent = (i % 3 === 0) ? h + 'h' : '';
                tax.appendChild(el);
            });
        } else {
            const dl = payload.day_labels || [];
            const nd = Math.ceil(hours.length/24);
            for(let d = 0; d < nd; d++){
                const el = document.createElement('div'); el.className = 'mod-tick';
                el.style.flex = String(Math.min(24, hours.length - d*24));
                el.textContent = ((dl[d]?.label) || (dl[d]?.raw) || '').split(' ')[0];
                tax.appendChild(el);
            }
        }
    }

    function buildModGrid(app, payload, models, hidden){
        const grid = document.getElementById('rp2-mod-grid'); if(!grid) return;
        grid.innerHTML = '';
        const hours = payload.hours || [];
        const mid = hours[Math.floor(hours.length/2)];
        const mkey = app.modVarObj.mkey, ckey = app.modVarObj.ckey;

        const cons = payload.consensus?.[mid]?.[ckey];
        const cc = document.createElement('div'); cc.className = 'mchip cons';
        cc.innerHTML = `<div class="chip-c" style="background:white;border-radius:50%"></div><span class="chip-n">Consensus</span><span class="chip-v">${cons != null ? Math.round(cons) : '—'}</span>`;
        grid.appendChild(cc);

        models.forEach(m => {
            const v = payload.data?.[mid]?.[m.id]?.[mkey];
            const chip = document.createElement('div'); chip.className = 'mchip' + (hidden[m.id] ? ' hid' : '');
            chip.innerHTML = `<div class="chip-c" style="background:${m.color || '#888'}"></div><span class="chip-n">${m.name}</span><span class="chip-v">${v != null ? Math.round(v) : '—'}</span>`;
            chip.onclick = () => app.toggleModel(m.id);
            grid.appendChild(chip);
        });
    }

    // ── Rendu complet de l'onglet Modèles ───────────────────────────────
    window.renderModelsTab = function(app){
        const payload = app.modelsPayload;
        const vk = app.modVarObj;
        const canvas = document.getElementById('rp2-ribbon-cv');
        if(!payload || !vk || !canvas) return;

        const hours  = payload.hours  || [];
        const models = payload.models || [];
        const hidden = app.modHidden  || {};
        const visible = models.filter(m => !hidden[m.id]);

        const getVal  = (h, id) => { const v = payload.data?.[h]?.[id]?.[vk.mkey]; return v == null ? null : +v; };
        const consVal = (h)     => { const v = payload.consensus?.[h]?.[vk.ckey]; return v == null ? null : +v; };

        const mins = hours.map(h => { const a = visible.map(m => getVal(h, m.id)).filter(v => v != null); return a.length ? Math.min(...a) : null; });
        const maxs = hours.map(h => { const a = visible.map(m => getVal(h, m.id)).filter(v => v != null); return a.length ? Math.max(...a) : null; });
        const cons = hours.map(h => consVal(h));

        const datasets = [];
        datasets.push({label:'mx', data:maxs, fill:'+1', backgroundColor:'rgba(255,255,255,0.06)', borderWidth:0, pointRadius:0, tension:.4, order:10, spanGaps:true});
        datasets.push({label:'mn', data:mins, fill:false, backgroundColor:'rgba(255,255,255,0.06)', borderWidth:0, pointRadius:0, tension:.4, order:11, spanGaps:true});
        visible.forEach(m => datasets.push({label:m.name, data:hours.map(h => getVal(h, m.id)), borderColor:(m.color || '#888') + '55', borderWidth:1, backgroundColor:'transparent', pointRadius:0, tension:.4, order:5, spanGaps:true}));
        datasets.push({label:'Consensus', data:cons, borderColor:'rgba(255,255,255,0.9)', borderWidth:2.5, borderDash:[5,3], backgroundColor:'transparent', pointRadius:0, tension:.4, order:1, spanGaps:true});

        let yMin, yMax;
        if(vk.wrap){ yMin = 0; yMax = 360; }
        else {
            const all = [];
            visible.forEach(m => hours.forEach(h => { const v = getVal(h, m.id); if(v != null) all.push(v); }));
            cons.forEach(v => { if(v != null) all.push(v); });
            if(all.length){ const mn = Math.min(...all), mx = Math.max(...all); const pad = Math.max((mx-mn)*0.1, 1); yMin = Math.max(0, mn-pad); yMax = mx+pad; }
            else { yMin = 0; yMax = 1; }
        }

        if(CHART) CHART.destroy();
        CHART = new Chart(canvas.getContext('2d'), {
            type:'line',
            data:{ labels: hours.map((_, i) => i), datasets },
            options:{
                responsive:true, maintainAspectRatio:false, animation:{duration:200},
                interaction:{mode:'index', intersect:false},
                plugins:{
                    legend:{display:false},
                    tooltip:{
                        backgroundColor:'#1a2a3a', borderColor:'rgba(255,255,255,0.1)', borderWidth:1,
                        titleColor:'rgba(255,255,255,0.38)', bodyColor:'rgba(255,255,255,0.68)', padding:6,
                        callbacks:{
                            title(items){ return formatHourLabel(app, payload, items[0]?.dataIndex ?? 0); },
                            label(c){ if(c.dataset.label === 'mx' || c.dataset.label === 'mn') return null; const y = c.parsed.y; return y == null ? null : ` ${c.dataset.label}: ${Math.round(y)} ${vk.unit}`; }
                        }
                    }
                },
                scales:{
                    x:{ grid:{color:'rgba(255,255,255,0.04)', drawTicks:false}, ticks:{display:false}, border:{color:'rgba(255,255,255,0.06)'} },
                    y:{ min:yMin, max:yMax, grid:{color:'rgba(255,255,255,0.04)', drawTicks:false}, ticks:{color:'rgba(255,255,255,0.2)', font:{size:9}, maxTicksLimit:4, callback:x => `${Math.round(x)}`}, border:{color:'rgba(255,255,255,0.06)'}, afterFit(a){ a.width = 28; } }
                }
            }
        });

        buildDivBar(app, payload, visible);
        buildModTax(app, payload);
        buildModGrid(app, payload, models, hidden);
    };

    window.destroyModelsChart = function(){ if(CHART){ CHART.destroy(); CHART = null; } };
})();
