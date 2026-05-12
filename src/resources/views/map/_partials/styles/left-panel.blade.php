        /* ── Volet gauche : onglets Paramètres / Légende (repliable) ── */
        #left-panel { flex:0 0 300px; background:#0f172a; border-right:1px solid rgba(55,65,81,.6); display:flex; flex-direction:column; overflow:hidden; transition:flex-basis .3s cubic-bezier(.4,0,.2,1); }
        #left-panel.collapsed { flex:0 0 44px; }

        .lp-head { display:flex; align-items:center; gap:10px; padding:12px; border-bottom:1px solid rgba(55,65,81,.5); flex-shrink:0; }
        #left-panel.collapsed .lp-head { padding:12px 6px; justify-content:center; }
        .lp-burger { background:rgba(31,41,55,.7); border:1px solid rgba(75,85,99,.5); color:#9ca3af; border-radius:8px; width:32px; height:32px; cursor:pointer; flex-shrink:0; font-size:15px; line-height:1; transition:color .15s, border-color .15s; }
        .lp-burger:hover { color:#fff; border-color:rgba(156,163,175,.6); }
        .lp-head-title { font-size:12px; font-weight:600; color:#cbd5e1; white-space:nowrap; }

        .lp-body { flex:1; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
        .lp-tabs { display:flex; flex-shrink:0; border-bottom:1px solid rgba(55,65,81,.5); }
        .lp-tab { flex:1; padding:10px 8px; background:transparent; border:none; border-bottom:2px solid transparent; color:#6b7280; font-size:13px; font-weight:500; cursor:pointer; transition:color .15s, border-color .15s; }
        .lp-tab:hover:not(.active) { color:#9ca3af; }
        .lp-tab.active { color:#fff; border-bottom-color:#38bdf8; }
        .lp-tabpane { flex:1; min-height:0; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:18px; }

        .lp-section { display:flex; flex-direction:column; gap:8px; }
        .lp-title { color:#4b5563; text-transform:uppercase; letter-spacing:.08em; font-size:9px; font-weight:600; }
        .lp-note { color:#374151; font-size:10px; line-height:1.5; }

        .dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .dot-green { background:#22c55e; } .dot-orange { background:#f59e0b; }
        .dot-red { background:#ef4444; }   .dot-grey { background:#6b7280; }

        .lp-toggle { display:flex; align-items:center; gap:10px; padding:9px 12px; border-radius:10px; background:#1f2937; border:1px solid rgba(75,85,99,.5); color:#9ca3af; font-size:13px; cursor:pointer; text-align:left; transition:background .15s, border-color .15s, color .15s; }
        .lp-toggle:hover { border-color:rgba(156,163,175,.6); }
        .lp-toggle .lp-lbl { flex:1; min-width:0; }
        .lp-toggle .lp-count { font-size:11px; color:#6b7280; }
        .lp-toggle .lp-switch { width:30px; height:16px; border-radius:999px; background:rgba(75,85,99,.6); position:relative; flex-shrink:0; transition:background .15s; }
        .lp-toggle .lp-switch::after { content:''; position:absolute; top:2px; left:2px; width:12px; height:12px; border-radius:50%; background:#9ca3af; transition:transform .15s, background .15s; }
        .lp-toggle.is-on { background:rgba(56,189,248,.16); border-color:rgba(56,189,248,.5); color:#fff; }
        .lp-toggle.is-on .lp-switch { background:rgba(56,189,248,.7); }
        .lp-toggle.is-on .lp-switch::after { transform:translateX(14px); background:#fff; }

        /* Légendes des pictogrammes (sites / balises) */
        .lp-legend-icons { display:flex; flex-direction:column; gap:8px; }
        .lp-li { display:flex; align-items:center; gap:14px; font-size:12px; color:#9ca3af; min-height:30px; }
        .lp-icon-ex { width:36px; flex-shrink:0; display:grid; place-items:center; }
        .lp-icon-ex .pg-site-marker { width:28px; height:28px; cursor:default; }
        .lp-icon-ex .pg-site-marker img { width:22px; height:22px; }
        .lp-icon-ex .pg-site-marker:hover { transform:none; }
        .lp-balise-ex { width:30px; height:30px; flex-shrink:0; }

        /* ── Volet droit : détail site / balise (s'ouvre à moitié d'écran) ── */
        #right-panel { flex:0 0 0; min-width:0; background:#0f172a; border-left:1px solid rgba(55,65,81,.6); display:flex; flex-direction:column; overflow:hidden; transition:flex-basis .35s cubic-bezier(.4,0,.2,1); }
        #right-panel.open { flex:0 0 50vw; }
        .rp-wrap { flex:1; min-width:50vw; min-height:0; display:flex; flex-direction:column; }

        /* Titre sur une seule ligne */
        .rp-head { display:flex; align-items:center; gap:12px; padding:13px 16px; border-bottom:1px solid rgba(55,65,81,.5); flex-shrink:0; }
        .rp-headline { flex:1; min-width:0; display:flex; align-items:center; gap:8px; white-space:nowrap; overflow:hidden; }
        .rp-name { font-size:16px; font-weight:600; color:#fff; flex-shrink:0; }
        .rp-meta { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#cbd5e1; flex-shrink:0; }
        .rp-dot { color:#475569; }
        .rp-sun-ico { color:#fbbf24; font-size:15px; line-height:1; }
        .rp-arrow { color:#64748b; }
        .rp-meta .mono { color:#fff; font-family:'DM Mono',monospace; }
        .rp-kind { font-size:9px; text-transform:uppercase; letter-spacing:.08em; font-weight:600; color:#4b5563; flex-shrink:0; }
        .rp-close { width:28px; height:28px; border-radius:50%; border:none; background:transparent; color:#6b7280; cursor:pointer; font-size:14px; flex-shrink:0; transition:background .15s, color .15s; }
        .rp-close:hover { background:rgba(55,65,81,.7); color:#fff; }

        /* Onglets */
        .rp-tabs { display:flex; flex-shrink:0; overflow-x:auto; border-bottom:1px solid rgba(55,65,81,.5); padding:0 8px; gap:2px; }
        .rp-tab { padding:11px 14px; background:transparent; border:none; border-bottom:2px solid transparent; color:#6b7280; font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; transition:color .15s, border-color .15s; }
        .rp-tab:hover:not(.active) { color:#9ca3af; }
        .rp-tab.active { color:#fff; border-bottom-color:#38bdf8; }

        .rp-pane { flex:1; min-height:0; overflow-y:auto; }
        .rp-pane:not(.rp-pane-scroll) { padding:16px; }

        .rp-loader { display:flex; align-items:center; justify-content:center; padding:60px; }
        .rp-spinner { width:28px; height:28px; border:2px solid #374151; border-top-color:#38bdf8; border-radius:50%; animation:spin 1s linear infinite; }
        .rp-placeholder { padding:48px 24px; text-align:center; font-size:13px; color:#4b5563; line-height:1.6; }

        .rp-confidence { margin-bottom:12px; }
        .rp-chart-box { background:rgba(13,27,38,.5); border:1px solid rgba(55,65,81,.4); border-radius:14px; padding:16px; }
        .rp-chart-legend { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-top:10px; font-size:12px; color:#cbd5e1; }
        .rp-chart-foot { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-top:12px; padding-top:10px; border-top:1px solid rgba(55,65,81,.3); font-size:12px; color:#9ca3af; }

        .rp-summary { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:14px 18px 4px; }
        .rp-range { font-size:13px; font-weight:500; color:#e5e7eb; }

        /* Sélecteur de jour flottant + contrôles de zoom Leaflet (haut-droite de la carte) */
        #day-selector { position:absolute; top:10px; right:10px; z-index:1000; }
        .leaflet-top.leaflet-right .leaflet-control-zoom { margin-top:60px; }
