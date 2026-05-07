/* Layout & marqueurs */
body { font-family:'DM Sans',sans-serif; }
.mono { font-family:'DM Mono',monospace; }
#pg-app  { display:flex; flex-direction:column; height:100%; overflow:hidden; }
#pg-toolbar { flex-shrink:0; height:56px; background:#111827; border-bottom:1px solid rgba(55,65,81,.6); display:flex; align-items:center; padding:0 16px; gap:12px; position:relative; z-index:100; }
#map-wrap { flex:1 1 0%; min-height:0; overflow:hidden; position:relative; }
#map { height:100%; width:100%; }

.pg-marker { cursor:pointer; transition:transform .15s,filter .15s; filter:drop-shadow(0 3px 6px rgba(0,0,0,.45)); display:block; }
.pg-marker:hover { transform:scale(1.15); filter:drop-shadow(0 4px 10px rgba(0,0,0,.6)); }
.pg-marker.selected { transform:scale(1.2); filter:drop-shadow(0 0 6px rgba(255,255,255,.7)) drop-shadow(0 4px 10px rgba(0,0,0,.6)); }

@keyframes spin { to { transform:rotate(360deg); } }

::-webkit-scrollbar { width:3px; }
::-webkit-scrollbar-thumb { background:#2d3748; border-radius:2px; }
.leaflet-tooltip { background:#0f172a !important; border:1px solid #1e293b !important; color:#cbd5e1 !important; font-family:'DM Sans',sans-serif !important; font-size:12px !important; padding:5px 10px !important; border-radius:8px !important; box-shadow:0 4px 16px rgba(0,0,0,.5) !important; }
.leaflet-tooltip-top:before { border-top-color:#1e293b !important; }
.leaflet-control-zoom a { background:#1e293b !important; color:#64748b !important; border-color:#334155 !important; }
.leaflet-control-zoom a:hover { background:#334155 !important; color:white !important; }
.leaflet-bar { border-color:#334155 !important; box-shadow:0 2px 8px rgba(0,0,0,.4) !important; }
