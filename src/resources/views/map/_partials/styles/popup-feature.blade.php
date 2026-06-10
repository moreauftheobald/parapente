/* ════════════════════════════════════════════════════════════════
   POPUP FLOTTANTE BALISE / STATION (refonte v2)
   Tout scopé sous .fpop. Popup ancrée au marqueur (position fixed,
   left/top calculés en JS), cohabite avec le drawer site.
   z-index 85 : au-dessus des tooltips (80), sous la modale rose (90).
   Mobile (<768px) : modale plein écran.
   ════════════════════════════════════════════════════════════════ */

.fpop{position:fixed;z-index:85;width:360px;max-width:360px;background:#111b28;border:0.5px solid rgba(255,255,255,0.1);border-radius:12px;overflow:hidden;font-family:'DM Sans',sans-serif;color:#e8f4fd;box-shadow:0 8px 40px rgba(0,0,0,0.6)}
.fpop *{box-sizing:border-box;margin:0;padding:0}

/* HEADER */
.fpop .hd{padding:11px 14px 9px;border-bottom:0.5px solid rgba(255,255,255,0.08)}
.fpop .hd-row{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.fpop .hd-name{font-size:14px;font-weight:500;color:#e8f4fd}
.fpop .hd-meta{display:flex;gap:8px;margin-top:3px;flex-wrap:wrap}
.fpop .hm{font-size:10px;color:rgba(255,255,255,0.55);display:flex;align-items:center;gap:3px}
.fpop .hm i{font-size:10px}
.fpop .hd-right{display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0}
.fpop .hd-actions{display:flex;align-items:center;gap:6px}
.fpop .live-dot{display:flex;align-items:center;gap:4px;font-size:10px;color:#4ade80}
.fpop .live-dot::before{content:'';width:6px;height:6px;border-radius:50%;background:#4ade80;display:block;animation:fpopPulse 2s infinite}
.fpop .ts{font-size:9px;color:rgba(255,255,255,0.42)}
.fpop .fpop-close{width:26px;height:26px;border-radius:6px;background:rgba(255,255,255,0.05);border:0.5px solid rgba(255,255,255,0.09);display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.45);font-size:14px;transition:background 0.15s,color 0.15s}
.fpop .fpop-close:hover{background:rgba(239,68,68,0.18);color:#f87171}

/* TABS */
.fpop .main-tabs{display:flex;background:rgba(255,255,255,0.03);border-bottom:0.5px solid rgba(255,255,255,0.07)}
.fpop .mtab{flex:1;padding:8px 4px;text-align:center;cursor:pointer;font-size:11px;color:rgba(255,255,255,0.38);border-bottom:2px solid transparent;transition:all 0.15s;display:flex;align-items:center;justify-content:center;gap:4px}
.fpop .mtab i{font-size:12px}
.fpop .mtab:hover{color:rgba(255,255,255,0.62)}
.fpop .mtab.on{color:#e8f4fd;border-bottom-color:#4ea8e0;background:rgba(78,168,224,0.05)}
.fpop .view{display:none}
.fpop .view.on{display:block}

/* VUE ROSE */
.fpop .rose-view{padding:12px 16px}
.fpop .period-row{display:flex;gap:4px;margin-bottom:10px}
.fpop .pbtn{padding:3px 9px;font-size:10px;border-radius:4px;cursor:pointer;border:0.5px solid rgba(255,255,255,0.12);color:rgba(255,255,255,0.45);background:transparent;transition:all 0.15s}
.fpop .pbtn:hover{color:rgba(255,255,255,0.7)}
.fpop .pbtn.on{background:rgba(78,168,224,0.15);border-color:rgba(78,168,224,0.35);color:#4ea8e0}
.fpop .rose-body{display:flex;gap:12px;align-items:center}
.fpop .rose-cv-wrap{flex-shrink:0}
.fpop .stats-col{flex:1;display:flex;flex-direction:column;gap:6px;min-width:0}
.fpop .stat{background:rgba(255,255,255,0.04);border:0.5px solid rgba(255,255,255,0.08);border-radius:8px;padding:7px 10px}
.fpop .stat.acc{background:rgba(78,168,224,0.08);border-color:rgba(78,168,224,0.2)}
.fpop .slbl{font-size:10px;color:rgba(255,255,255,0.52);margin-bottom:2px;display:flex;align-items:center;gap:3px}
.fpop .slbl i{font-size:10px}
.fpop .sval{font-size:18px;font-weight:500;line-height:1}
.fpop .sunit{font-size:10px;color:rgba(255,255,255,0.38);margin-left:2px;font-weight:400}
.fpop .ssub{font-size:10px;color:rgba(255,255,255,0.45);margin-top:2px}
.fpop .sbar{height:3px;background:rgba(255,255,255,0.08);border-radius:2px;overflow:hidden;margin-top:4px}
.fpop .sbar-f{height:100%;border-radius:2px;transition:width 0.35s,background 0.35s}
.fpop .rose-legend{margin-top:10px;display:flex;align-items:center;gap:8px}
.fpop .rl-lbl{font-size:9px;color:rgba(255,255,255,0.42)}
.fpop .rose-legend canvas{display:block}

/* loader / placeholder */
.fpop .fp-loader{display:flex;align-items:center;justify-content:center;padding:48px}
.fpop .fp-spinner{width:26px;height:26px;border:2px solid rgba(255,255,255,0.12);border-top-color:#4ea8e0;border-radius:50%;animation:fpopSpin 1s linear infinite}
.fpop .fp-placeholder{padding:40px 20px;text-align:center;font-size:12px;color:rgba(255,255,255,0.45);line-height:1.6}

@keyframes fpopPulse{0%,100%{opacity:1}50%{opacity:.35}}
@keyframes fpopSpin{to{transform:rotate(360deg)}}

@verbatim
@media (max-width:767px){
  .fpop{position:fixed !important;inset:0 !important;left:0 !important;top:0 !important;width:100vw;max-width:100vw;height:100vh;height:100dvh;border-radius:0;z-index:88;overflow-y:auto}
}
@endverbatim
