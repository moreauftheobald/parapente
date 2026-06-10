/* ════════════════════════════════════════════════════════════════
   PANNEAU DROIT v2 — refonte (Synthèse / Scoring / Modèles)
   Tout est scopé sous `.rp2` pour isoler de l'existant (#right-panel,
   .rp-*, styles Leaflet…). Palette dark en dur (pas de var framework) :
     fond panel      #111b28   fond secondaire #0f1923
     vert  #16a34a / texte #4ade80
     orange#d97706 / texte #fbbf24
     rouge #dc2626 / texte #f87171
     bleu axe #4ea8e0           violet perso rgba(167,139,250,…)
   Le rendu canvas (nuages / vent / scoring) + Chart.js (ribbon)
   arrive en Phase 1+ ; ce fichier ne porte que la structure visuelle.
   ════════════════════════════════════════════════════════════════ */

.rp2 *{box-sizing:border-box;margin:0;padding:0}
/* Remplit le flex column #right-panel (height:100%) et scrolle en interne. */
.rp2{background:#111b28;font-family:'DM Sans',sans-serif;width:100%;color:#e8f4fd;flex:1;min-width:0;min-height:0;overflow-y:auto;overflow-x:hidden}

/* ─── En-tête site ─── */
.rp2 .panel{background:#111b28;overflow:hidden;width:100%;max-width:none;margin:0}
.rp2 .site-hd{padding:11px 14px 9px;border-bottom:0.5px solid rgba(255,255,255,0.08)}
.rp2 .site-hd-row{display:flex;align-items:flex-start;justify-content:space-between}
.rp2 .site-name{font-size:14px;font-weight:500;color:#e8f4fd}
.rp2 .site-meta{display:flex;gap:7px;margin-top:3px;flex-wrap:wrap}
.rp2 .smeta{font-size:10px;color:rgba(255,255,255,0.32);display:flex;align-items:center;gap:3px}
.rp2 .smeta i{font-size:10px}
.rp2 .hero-num{font-size:25px;font-weight:500;line-height:1}
.rp2 .hero-lbl{font-size:9px;color:rgba(255,255,255,0.28);text-align:right;margin-top:1px}

/* ─── Onglets principaux ─── */
.rp2 .main-tabs{display:flex;background:rgba(255,255,255,0.03);border-bottom:0.5px solid rgba(255,255,255,0.07)}
.rp2 .mtab{flex:1;padding:8px 4px;text-align:center;cursor:pointer;font-size:11px;color:rgba(255,255,255,0.32);border-bottom:2px solid transparent;transition:all 0.15s;display:flex;align-items:center;justify-content:center;gap:4px}
.rp2 .mtab i{font-size:12px}
.rp2 .mtab:hover{color:rgba(255,255,255,0.62)}
.rp2 .mtab.on{color:#e8f4fd;border-bottom-color:#4ea8e0;background:rgba(78,168,224,0.05)}
.rp2 .view{display:none}
.rp2 .view.on{display:block}

/* ═══ SYNTHÈSE ═══ */
.rp2 .hero-strip{margin:10px 14px;background:rgba(22,163,74,0.1);border:0.5px solid rgba(22,163,74,0.24);border-radius:9px;padding:9px 12px;display:flex;align-items:center;gap:10px}
.rp2 .hero-dot{width:30px;height:30px;border-radius:50%;background:#16a34a;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.rp2 .hero-dot i{font-size:14px;color:#fff}
.rp2 .hverdict{font-size:12px;font-weight:500;color:#4ade80}
.rp2 .hwin{font-size:10px;color:rgba(255,255,255,0.32);margin-top:2px}
.rp2 .hbig{font-size:22px;font-weight:500;color:#4ade80;margin-left:auto;flex-shrink:0}
.rp2 .block{padding:0 14px;margin-bottom:11px}
.rp2 .blbl{font-size:10px;color:rgba(255,255,255,0.25);margin-bottom:5px;display:flex;justify-content:space-between;align-items:center}
.rp2 .blbl i{font-size:10px}
.rp2 .cloud-wrap{display:flex;gap:2px;height:42px}
.rp2 .cloud-col{flex:1;border-radius:2px;overflow:hidden}
.rp2 .cloud-col canvas{display:block}
.rp2 .cax{display:flex;justify-content:space-between;margin-top:3px}
.rp2 .cax span{font-size:9px;color:rgba(255,255,255,0.18)}
.rp2 .chart-outer{position:relative;width:100%}
.rp2 .chart-outer canvas{display:block;width:100%;height:100px;cursor:crosshair}
.rp2 .hover-line{position:absolute;top:0;width:1px;background:rgba(255,255,255,0.15);pointer-events:none;display:none;height:100px}
.rp2 .tt{position:absolute;background:#182535;border:0.5px solid rgba(255,255,255,0.14);border-radius:7px;padding:7px 9px;pointer-events:none;display:none;z-index:80;min-width:138px}
.rp2 .tt-h{font-size:10px;font-weight:500;color:#e8f4fd;margin-bottom:4px;padding-bottom:3px;border-bottom:0.5px solid rgba(255,255,255,0.07)}
.rp2 .tt-r{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:1.5px 0}
.rp2 .tt-l{font-size:10px;color:rgba(255,255,255,0.38);display:flex;align-items:center;gap:3px}
.rp2 .tt-l i{font-size:10px}
.rp2 .tt-v{font-size:10px;font-weight:500}
.rp2 .tt-sep{height:0.5px;background:rgba(255,255,255,0.07);margin:3px 0}
.rp2 .dir-strip{display:flex;gap:2px;margin-top:2px}
.rp2 .dir-arrow{flex:1;display:flex;align-items:center;justify-content:center;height:14px}
.rp2 .axis-strip{display:flex;gap:2px;margin-top:2px}
.rp2 .asc{flex:1;height:3px;border-radius:1px}
.rp2 .wax{display:flex;justify-content:space-between;margin-top:2px}
.rp2 .wax span{font-size:9px;color:rgba(255,255,255,0.18)}
.rp2 .wind-leg{display:flex;gap:7px;margin-top:5px;flex-wrap:wrap}
.rp2 .wleg{display:flex;align-items:center;gap:3px;font-size:9px;color:rgba(255,255,255,0.25)}
.rp2 .wleg-b{width:9px;height:4px;border-radius:1px}
.rp2 .wleg-d{width:9px;height:0;border-top:1.5px dashed rgba(255,255,255,0.4)}
.rp2 .wleg-l{width:9px;height:1.5px;border-radius:1px}
.rp2 .mini-rose{display:flex;align-items:center;gap:9px;background:rgba(255,255,255,0.03);border:0.5px solid rgba(255,255,255,0.07);border-radius:7px;padding:7px 11px;margin:0 14px 11px;cursor:pointer;transition:background 0.15s}
.rp2 .mini-rose:hover{background:rgba(255,255,255,0.055)}
.rp2 .mr-lbl{font-size:9px;color:rgba(255,255,255,0.25);margin-bottom:3px}
.rp2 .mr-row{display:flex;gap:9px}
.rp2 .mrv{font-size:12px;font-weight:500}
.rp2 .mrvu{font-size:9px;color:rgba(255,255,255,0.28);font-weight:400}
.rp2 .mrsub{font-size:9px;color:rgba(255,255,255,0.25);margin-top:1px}
.rp2 .cert{margin:0 14px 13px;background:rgba(255,255,255,0.03);border:0.5px solid rgba(255,255,255,0.07);border-radius:7px;padding:8px 11px}
.rp2 .cert-row{display:flex;justify-content:space-between;margin-bottom:5px}
.rp2 .cert-lbl{font-size:10px;color:rgba(255,255,255,0.3)}
.rp2 .cert-val{font-size:12px;font-weight:500;color:#4ea8e0}
.rp2 .cert-bg{height:3px;background:rgba(255,255,255,0.07);border-radius:2px;overflow:hidden}
.rp2 .cert-fill{height:100%;background:#4ea8e0;border-radius:2px}
.rp2 .cert-sub{font-size:9px;color:rgba(255,255,255,0.2);margin-top:4px}

/* ═══ SCORING ═══ */
.rp2 .sc-ctrl{display:flex;align-items:center;justify-content:space-between;padding:6px 14px;border-bottom:0.5px solid rgba(255,255,255,0.06);gap:6px}
.rp2 .tog{display:flex;background:rgba(255,255,255,0.05);border-radius:5px;border:0.5px solid rgba(255,255,255,0.08);overflow:hidden}
.rp2 .togtab{padding:3px 8px;font-size:10px;color:rgba(255,255,255,0.32);cursor:pointer;transition:all 0.15s;display:flex;align-items:center;gap:3px;white-space:nowrap}
.rp2 .togtab i{font-size:10px}
.rp2 .togtab.on{background:rgba(78,168,224,0.18);color:#4ea8e0}
.rp2 .togtab.on-p{background:rgba(167,139,250,0.15);color:#a78bfa}
.rp2 .togtab:hover:not(.on):not(.on-p){color:rgba(255,255,255,0.6)}
.rp2 .dnav{display:flex;align-items:center;gap:3px}
.rp2 .dnavb{width:20px;height:20px;border-radius:4px;background:rgba(255,255,255,0.05);border:0.5px solid rgba(255,255,255,0.07);display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.35);font-size:10px;transition:all 0.15s}
.rp2 .dnavb:hover{color:#e8f4fd;background:rgba(255,255,255,0.09)}
.rp2 .dnavb.off{opacity:0.2;pointer-events:none}
.rp2 .dlbl{font-size:10px;color:#e8f4fd;min-width:58px;text-align:center}
.rp2 .sc-grid{padding:7px 14px 2px}
.rp2 .sgh{font-size:9px;color:rgba(255,255,255,0.2);letter-spacing:0.06em;text-transform:uppercase;display:flex;align-items:center;gap:4px;margin-bottom:4px}
.rp2 .sgh i{font-size:10px}
.rp2 .sgh-badge{font-size:9px;color:rgba(255,255,255,0.2);background:rgba(255,255,255,0.04);border-radius:3px;padding:1px 5px;margin-left:auto}
.rp2 .prow{display:flex;align-items:center;gap:6px;margin-bottom:3px}
.rp2 .plbl{font-size:10px;color:rgba(255,255,255,0.36);width:70px;flex-shrink:0;text-align:right}
.rp2 .plbl.gb{font-weight:500;color:rgba(255,255,255,0.58)}
.rp2 .track{flex:1;border-radius:3px;overflow:hidden}
.rp2 .track canvas{display:block}
.rp2 .sc-div{height:0.5px;background:rgba(255,255,255,0.06);margin:5px 14px}
.rp2 .sc-tax{display:flex;margin-top:2px}
.rp2 .sctick{font-size:9px;color:rgba(255,255,255,0.16);text-align:center}
.rp2 .day-strip{display:flex;gap:2px;padding:5px 14px 0}
.rp2 .dsc{flex:1;background:rgba(255,255,255,0.03);border:0.5px solid rgba(255,255,255,0.06);border-radius:4px;padding:4px 3px;cursor:pointer;text-align:center;transition:all 0.15s}
.rp2 .dsc.on{border-color:rgba(78,168,224,0.35);background:rgba(78,168,224,0.07)}
.rp2 .dsc-d{font-size:9px;color:rgba(255,255,255,0.25)}
.rp2 .dsc-b{height:3px;border-radius:1px;margin:2px 0}
.rp2 .dsc-s{font-size:10px;font-weight:500}
.rp2 .sc-leg{display:flex;gap:7px;padding:5px 14px 11px;flex-wrap:wrap}
.rp2 .sleg{display:flex;align-items:center;gap:3px;font-size:9px;color:rgba(255,255,255,0.25)}
.rp2 .sleg-dot{width:6px;height:6px;border-radius:50%}
/* État « à venir » (stub Qualité de vol) */
.rp2 .sc-stub{position:relative}
.rp2 .sc-stub .prow{opacity:0.4}
.rp2 .sc-soon{font-size:9px;color:rgba(255,255,255,0.3);background:rgba(255,255,255,0.05);border:0.5px solid rgba(255,255,255,0.08);border-radius:3px;padding:1px 6px;margin-left:auto}

/* ═══ MODÈLES ═══ */
.rp2 .mod-vtabs{display:flex;overflow-x:auto;scrollbar-width:none;border-bottom:0.5px solid rgba(255,255,255,0.07)}
.rp2 .mod-vtabs::-webkit-scrollbar{display:none}
.rp2 .vtab{padding:6px 10px;font-size:10px;color:rgba(255,255,255,0.28);cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:3px;transition:all 0.15s;flex-shrink:0}
.rp2 .vtab i{font-size:10px}
.rp2 .vtab.on{color:#4ea8e0;border-bottom-color:#4ea8e0}
.rp2 .vtab:hover:not(.on){color:rgba(255,255,255,0.58)}
.rp2 .mod-sub{display:flex;align-items:center;justify-content:space-between;padding:5px 14px;border-bottom:0.5px solid rgba(255,255,255,0.06)}
.rp2 .conform-pill{background:rgba(74,222,128,0.08);border:0.5px solid rgba(74,222,128,0.2);border-radius:20px;padding:2px 8px;font-size:10px;color:#4ade80;display:flex;align-items:center;gap:3px;flex-shrink:0}
.rp2 .mod-chart-area{padding:7px 14px 3px}
.rp2 .mch-hdr{display:flex;justify-content:space-between;margin-bottom:4px}
.rp2 .mch-lbl{font-size:10px;color:rgba(255,255,255,0.26);display:flex;align-items:center;gap:3px}
.rp2 .mch-lbl i{font-size:10px}
.rp2 .mch-unit{font-size:9px;color:rgba(255,255,255,0.18)}
.rp2 .ribbon-wrap{position:relative;width:100%;height:108px}
.rp2 .ribbon-wrap canvas{display:block;width:100%;height:108px}
.rp2 .div-bar{display:flex;gap:1px;height:3px;margin:2px 14px}
.rp2 .div-seg{flex:1;border-radius:1px}
.rp2 .mod-tax{display:flex;padding:0 14px;margin-top:1px}
.rp2 .mod-tick{flex:1;font-size:9px;color:rgba(255,255,255,0.16);text-align:center}
.rp2 .mod-leg{display:flex;gap:9px;padding:3px 14px 7px;flex-wrap:wrap}
.rp2 .mleg{display:flex;align-items:center;gap:3px;font-size:9px;color:rgba(255,255,255,0.2)}
.rp2 .mleg-line{width:12px;height:1.5px;border-radius:1px;background:rgba(255,255,255,0.25)}
.rp2 .mleg-dash{width:12px;height:0;border-top:2px dashed rgba(255,255,255,0.4)}
.rp2 .mleg-band{width:12px;height:5px;border-radius:1px;background:rgba(255,255,255,0.07)}
.rp2 .mod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:3px;padding:6px 14px 12px;border-top:0.5px solid rgba(255,255,255,0.06)}
.rp2 .mchip{background:rgba(255,255,255,0.03);border:0.5px solid rgba(255,255,255,0.06);border-radius:4px;padding:4px 7px;cursor:pointer;transition:all 0.15s;display:flex;align-items:center;gap:5px}
.rp2 .mchip:hover{background:rgba(255,255,255,0.06)}
.rp2 .mchip.hid{opacity:0.24}
.rp2 .mchip.cons{border-color:rgba(255,255,255,0.18);background:rgba(255,255,255,0.05)}
.rp2 .chip-c{width:7px;height:7px;border-radius:2px;flex-shrink:0}
.rp2 .chip-n{font-size:9px;color:rgba(255,255,255,0.44);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rp2 .chip-v{font-size:9px;font-weight:500;color:rgba(255,255,255,0.68)}
.rp2 .mchip.cons .chip-n{color:rgba(255,255,255,0.75);font-weight:500}
