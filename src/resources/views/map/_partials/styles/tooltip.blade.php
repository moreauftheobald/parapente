        /* Tooltip multi-modèles au survol des graphes */
        #chart-tooltip { position:fixed; z-index:9999; background:#0f172a; border:1px solid rgba(75,85,99,.7); border-radius:10px; padding:10px 12px; font-size:11px; color:#e5e7eb; pointer-events:none; box-shadow:0 12px 32px rgba(0,0,0,.7); min-width:200px; max-width:260px; }
        #chart-tooltip .tt-hour { font-size:13px; font-weight:600; color:#fff; margin-bottom:8px; font-family:'DM Mono',monospace; }
        #chart-tooltip .tt-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:2px 0; }
        #chart-tooltip .tt-row .name { display:flex; align-items:center; gap:6px; color:#cbd5e1; font-size:11px; }
        #chart-tooltip .tt-row .name .dot { width:9px; height:3px; border-radius:1px; flex-shrink:0; }
        #chart-tooltip .tt-row .val { color:#fff; font-family:'DM Mono',monospace; font-size:11px; white-space:nowrap; }
        #chart-tooltip .tt-row.consensus { padding-bottom:5px; margin-bottom:5px; border-bottom:1px solid rgba(55,65,81,.5); }
        #chart-tooltip .tt-row.consensus .name { color:#fff; font-weight:600; }
        #chart-tooltip .tt-row.consensus .name .dot { background-image:repeating-linear-gradient(90deg,#fff 0 3px,transparent 3px 6px); height:2px; }
        #chart-tooltip .tt-empty { color:#6b7280; font-style:italic; padding:2px 0; font-size:10px; }
