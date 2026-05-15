/* Layout & marqueurs */
body { font-family:'DM Sans',sans-serif; }
.mono { font-family:'DM Mono',monospace; }
/* Conteneur de la carte : le <main> du shell occupe tout l'espace dispo
   moins la navbar (h-14). #map-area occupe ensuite tout le <main>.
   `isolation: isolate` est CRITIQUE : sans ça, les z-index élevés
   utilisés par Leaflet en interne (tile-pane 200, marker-pane 600,
   control 800…) fuient hors de #map-area et passent devant les
   volets latéraux du shell (z-40), rendant ces derniers invisibles. */
#map-area { width:100%; height:100%; min-width:0; position:relative; isolation:isolate; }
#map { height:100%; width:100%; }

/* Marqueurs des sites de vol — icône SpotAir + glow flou coloré
   selon statut météo. Glow plutôt que cercle solide pour mieux
   contraster avec les fonds de carte (notamment topo, où il y a
   déjà beaucoup de vert "nature"). Vert pomme/citron volontairement
   lumineux pour ressortir du décor. */
.pg-site-marker { position:relative; width:38px; height:38px; border-radius:50%; display:grid; place-items:center; cursor:pointer; transition:transform .15s, filter .15s; }
.pg-site-marker img { width:30px; height:30px; pointer-events:none; }

/* Badge user_scoring (coin bas-droite du marker). Échappe au filter glow
   du parent grâce à isolation:isolate côté wrapper — au pire le badge
   s'enflamme visuellement avec le halo, mais reste lisible grâce au
   contraste de ses propres background + border. */
.pg-user-badge { position:absolute; right:-2px; bottom:-2px; width:13px; height:13px; border-radius:50%; box-sizing:border-box; pointer-events:none; }
.pg-user-badge-active   { background:#38bdf8; border:2px solid #0f172a; box-shadow:0 0 6px rgba(56,189,248,.7), 0 0 2px rgba(0,0,0,.8); }
.pg-user-badge-inactive { background:transparent; border:2px solid #9ca3af; box-shadow:0 0 0 1px rgba(0,0,0,.8); }
.pg-site-marker.pg-status-green   { filter:drop-shadow(0 0 6px #3BFF00) drop-shadow(0 0 14px #3BFF00) drop-shadow(0 0 22px rgba(59,255,0,.85)) drop-shadow(0 2px 3px rgba(0,0,0,.7)); }
.pg-site-marker.pg-status-orange  { filter:drop-shadow(0 0 6px #fb923c) drop-shadow(0 0 14px #fb923c) drop-shadow(0 0 22px rgba(251,146,60,.85))  drop-shadow(0 2px 3px rgba(0,0,0,.7)); }
.pg-site-marker.pg-status-red     { filter:drop-shadow(0 0 6px #f87171) drop-shadow(0 0 14px #f87171) drop-shadow(0 0 22px rgba(248,113,113,.85)) drop-shadow(0 2px 3px rgba(0,0,0,.7)); }
.pg-site-marker.pg-status-unknown { filter:drop-shadow(0 0 3px rgba(255,255,255,.6)) drop-shadow(0 2px 3px rgba(0,0,0,.6)); }
.pg-site-marker:hover { transform:scale(1.18); }
.pg-site-marker.selected { transform:scale(1.25); }
.pg-site-marker.pg-status-green.selected   { filter:drop-shadow(0 0 8px #3BFF00) drop-shadow(0 0 18px #3BFF00)  drop-shadow(0 0 26px rgba(59,255,0,.9))  drop-shadow(0 0 3px #fff); }
.pg-site-marker.pg-status-orange.selected  { filter:drop-shadow(0 0 8px #fb923c) drop-shadow(0 0 18px #fb923c) drop-shadow(0 0 26px rgba(251,146,60,.9)) drop-shadow(0 0 3px #fff); }
.pg-site-marker.pg-status-red.selected     { filter:drop-shadow(0 0 8px #f87171) drop-shadow(0 0 18px #f87171) drop-shadow(0 0 26px rgba(248,113,113,.9)) drop-shadow(0 0 3px #fff); }
.pg-site-marker.pg-status-unknown.selected { filter:drop-shadow(0 0 4px #fff) drop-shadow(0 0 10px rgba(255,255,255,.7)); }

@keyframes spin { to { transform:rotate(360deg); } }

::-webkit-scrollbar { width:3px; }
::-webkit-scrollbar-thumb { background:#2d3748; border-radius:2px; }
.leaflet-tooltip { background:#0f172a !important; border:1px solid #1e293b !important; color:#cbd5e1 !important; font-family:'DM Sans',sans-serif !important; font-size:12px !important; padding:5px 10px !important; border-radius:8px !important; box-shadow:0 4px 16px rgba(0,0,0,.5) !important; }
.leaflet-tooltip-top:before { border-top-color:#1e293b !important; }
.leaflet-control-zoom a { background:#1e293b !important; color:#64748b !important; border-color:#334155 !important; }
.leaflet-control-zoom a:hover { background:#334155 !important; color:white !important; }
.leaflet-bar { border-color:#334155 !important; box-shadow:0 2px 8px rgba(0,0,0,.4) !important; }
