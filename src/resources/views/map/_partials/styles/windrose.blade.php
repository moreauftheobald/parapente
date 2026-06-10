/* ════════════════════════════════════════════════════════════════
   ROSE DES VENTS ANIMÉE — modale (ouverte depuis la mini-rose Synthèse)
   Tout scopé sous .wr-modal / .wr-panel. Palette dark en dur.
   z-index 90 : au-dessus de toute l'UI carte (tooltips 80) — c'est
   une couche modale plein écran (cf. échelle z-index, CLAUDE.md).
   ════════════════════════════════════════════════════════════════ */

.wr-modal{position:fixed;inset:0;z-index:90;display:flex;align-items:center;justify-content:center;background:rgba(8,12,20,0.62);padding:16px}
.wr-modal *{box-sizing:border-box;margin:0;padding:0}

.wr-panel{position:relative;background:#0f1923;border:0.5px solid rgba(255,255,255,0.1);border-radius:12px;overflow:hidden;font-family:'DM Sans',sans-serif;width:100%;max-width:460px;color:#e8f4fd;box-shadow:0 20px 60px rgba(0,0,0,0.5)}

.wr-modal .wr-topbar{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;border-bottom:0.5px solid rgba(255,255,255,0.08)}
.wr-modal .wr-site{display:flex;flex-direction:column;gap:2px;min-width:0}
.wr-modal .wr-site-name{font-size:14px;font-weight:500;color:#e8f4fd}
.wr-modal .wr-site-meta{display:flex;gap:8px;flex-wrap:wrap;font-size:10px;color:rgba(255,255,255,0.32)}
.wr-modal .wr-site-meta span{display:flex;align-items:center;gap:3px}
.wr-modal .wr-site-meta i{font-size:10px}

.wr-modal .wr-controls{display:flex;align-items:center;gap:6px;flex-shrink:0}
.wr-modal .wr-btn{width:30px;height:30px;border-radius:6px;background:rgba(255,255,255,0.05);border:0.5px solid rgba(255,255,255,0.09);display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.45);font-size:14px;transition:background 0.15s,color 0.15s}
.wr-modal .wr-btn:hover{background:rgba(255,255,255,0.09);color:#e8f4fd}
.wr-modal .wr-btn.playing{background:rgba(78,168,224,0.14);border-color:rgba(78,168,224,0.38);color:#4ea8e0}
.wr-modal .wr-time{font-size:14px;font-weight:500;color:#e8f4fd;min-width:34px;text-align:center}
.wr-modal .wr-close{width:30px;height:30px;border-radius:6px;background:rgba(255,255,255,0.05);border:0.5px solid rgba(255,255,255,0.09);display:flex;align-items:center;justify-content:center;cursor:pointer;color:rgba(255,255,255,0.45);font-size:15px;margin-left:4px;transition:background 0.15s,color 0.15s}
.wr-modal .wr-close:hover{background:rgba(239,68,68,0.18);color:#f87171}

.wr-modal .wr-body{display:flex;align-items:stretch}
.wr-modal .wr-rose-col{display:flex;flex-direction:column;align-items:center;padding:16px 10px 12px;flex:0 0 240px}
.wr-modal .wr-svg-wrap{width:210px;height:210px;position:relative}
.wr-modal .wr-svg{width:100%;height:100%;overflow:visible}
.wr-modal .wr-rose-legend{display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;justify-content:center}
.wr-modal .wr-leg{display:flex;align-items:center;gap:4px;font-size:10px;color:rgba(255,255,255,0.25)}
.wr-modal .wr-leg-line{width:16px;height:2px;border-radius:1px}
.wr-modal .wr-leg-ring{width:12px;height:12px;border-radius:50%;border:1.5px solid rgba(78,168,224,0.4)}
.wr-modal .wr-leg-sector{width:14px;height:8px;border-radius:2px;background:rgba(78,168,224,0.1);border:0.5px solid rgba(78,168,224,0.25)}
.wr-modal .wr-leg-dot{width:8px;height:8px;border-radius:50%}

.wr-modal .wr-stats-col{flex:1;display:flex;flex-direction:column;justify-content:center;padding:14px 14px 14px 4px;gap:8px;min-width:0}
.wr-modal .wr-stat{background:rgba(255,255,255,0.035);border:0.5px solid rgba(255,255,255,0.07);border-radius:8px;padding:9px 11px}
.wr-modal .wr-stat.accent{background:rgba(78,168,224,0.08);border-color:rgba(78,168,224,0.2)}
.wr-modal .wr-stat-label{font-size:10px;color:rgba(255,255,255,0.28);margin-bottom:4px;display:flex;align-items:center;gap:4px}
.wr-modal .wr-stat-label i{font-size:10px}
.wr-modal .wr-stat-val{font-size:21px;font-weight:500;line-height:1}
.wr-modal .wr-stat-unit{font-size:10px;font-weight:400;color:rgba(255,255,255,0.3);margin-left:2px}
.wr-modal .wr-stat-sub{font-size:10px;color:rgba(255,255,255,0.28);margin-top:3px}
.wr-modal .wr-bar-bg{height:3px;background:rgba(255,255,255,0.07);border-radius:2px;margin-top:7px;overflow:hidden}
.wr-modal .wr-bar-fill{height:100%;border-radius:2px;transition:width 0.4s,background 0.4s}

.wr-modal .wr-timeline{padding:8px 14px 6px;border-top:0.5px solid rgba(255,255,255,0.06)}
.wr-modal .wr-tl-header{display:flex;justify-content:space-between;font-size:10px;color:rgba(255,255,255,0.22);margin-bottom:5px}
.wr-modal .wr-tl-track{display:flex;gap:2px;align-items:flex-end;height:32px;cursor:pointer}
.wr-modal .wr-tl-col{flex:1;display:flex;flex-direction:column;align-items:stretch;gap:1px;cursor:pointer}
.wr-modal .wr-tl-col.active .wr-tl-gust,.wr-modal .wr-tl-col.active .wr-tl-mean{box-shadow:0 0 0 1px rgba(255,255,255,0.5)}
.wr-modal .wr-tl-gust{border-radius:1px 1px 0 0;min-height:1px}
.wr-modal .wr-tl-mean{min-height:1px}
.wr-modal .wr-tl-dir{display:flex;gap:2px;margin-top:2px}
.wr-modal .wr-tl-dir-cell{flex:1;height:4px;border-radius:1px}
.wr-modal .wr-tl-hours{display:flex;justify-content:space-between;margin-top:3px;font-size:9px;color:rgba(255,255,255,0.18)}

.wr-modal .wr-verdict{display:flex;align-items:center;gap:8px;padding:7px 14px 10px}
.wr-modal .wr-verdict-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.wr-modal .wr-verdict-text{font-size:12px;color:rgba(255,255,255,0.45)}
.wr-modal .wr-verdict-text strong{font-weight:500;color:rgba(255,255,255,0.75)}

@verbatim
@media (max-width:520px){
  .wr-modal .wr-body{flex-direction:column}
  .wr-modal .wr-rose-col{flex:0 0 auto}
  .wr-modal .wr-stats-col{padding:0 14px 12px}
}
@endverbatim
