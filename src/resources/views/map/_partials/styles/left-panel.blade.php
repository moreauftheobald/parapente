        /* -- Volet gauche : onglets Parametres / Legende --
           Le wrapper (largeur, overlay mobile, fermeture) est pilote par
           le shell global. Ici on ne fait que styler le contenu interne. */
        #left-panel { display:flex; flex-direction:column; height:100%; overflow:hidden; }
        .lp-body { flex:1; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
        .lp-tabs { display:flex; flex-shrink:0; border-bottom:1px solid var(--c-border-5); }
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
        .dot-mine { background:#38bdf8; box-shadow:0 0 4px rgba(56,189,248,.6); }

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

        /* -- Volet droit : detail site / balise --
           Largeur, animation et ouverture/fermeture pilotes par le shell.
           Ici, on ne fait que styler le contenu interne. */
        #right-panel { display:flex; flex-direction:column; height:100%; overflow:hidden; }
        .rp-wrap { flex:1; min-width:0; min-height:0; display:flex; flex-direction:column; }

        /* Titre sur une seule ligne */
        .rp-head { display:flex; align-items:center; gap:12px; padding:13px 16px; border-bottom:1px solid var(--c-border-5); flex-shrink:0; }
        .rp-headline { flex:1; min-width:0; display:flex; align-items:center; gap:8px; white-space:nowrap; overflow:hidden; }
        .rp-name { font-size:16px; font-weight:600; color:#fff; flex-shrink:0; }
        .rp-meta { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#cbd5e1; flex-shrink:0; }
        .rp-dot { color:#475569; }
        .rp-sun-ico { color:#fbbf24; font-size:15px; line-height:1; }
        .rp-arrow { color:#64748b; }
        .rp-meta .mono { color:#fff; font-family:var(--font-mono); }
        .rp-kind { font-size:9px; text-transform:uppercase; letter-spacing:.08em; font-weight:600; color:#4b5563; flex-shrink:0; }
        .rp-close { width:28px; height:28px; border-radius:50%; border:none; background:transparent; color:#6b7280; cursor:pointer; font-size:14px; flex-shrink:0; transition:background .15s, color .15s; }
        .rp-close:hover { background:rgba(55,65,81,.7); color:#fff; }

        /* Onglets */
        .rp-user-banner { display:flex; align-items:center; gap:8px; padding:8px 14px; background:linear-gradient(90deg, rgba(56,189,248,.18), rgba(56,189,248,.04)); border-bottom:1px solid rgba(56,189,248,.25); font-size:12px; color:#bae6fd; flex-shrink:0; }
        .rp-user-banner-ico { color:#38bdf8; font-size:13px; line-height:1; }
        .rp-user-banner-name { color:#fff; font-weight:600; }
        .rp-user-banner-link { margin-left:auto; color:#7dd3fc; text-decoration:none; font-size:11px; transition:color .15s; }
        .rp-user-banner-link:hover { color:#fff; text-decoration:underline; }

        .rp-tabs { display:flex; flex-shrink:0; overflow-x:auto; border-bottom:1px solid var(--c-border-5); padding:0 8px; gap:2px; }
        .rp-tab { padding:11px 14px; background:transparent; border:none; border-bottom:2px solid transparent; color:#6b7280; font-size:13px; font-weight:500; cursor:pointer; white-space:nowrap; transition:color .15s, border-color .15s; }
        .rp-tab:hover:not(.active) { color:#9ca3af; }
        .rp-tab.active { color:#fff; border-bottom-color:#38bdf8; }

        .rp-pane { flex:1; min-height:0; overflow-y:auto; }
        .rp-pane:not(.rp-pane-scroll) { padding:16px; }

        .rp-loader { display:flex; align-items:center; justify-content:center; padding:60px; }
        .rp-spinner { width:28px; height:28px; border:2px solid #374151; border-top-color:#38bdf8; border-radius:50%; animation:spin 1s linear infinite; }
        .rp-placeholder { padding:48px 24px; text-align:center; font-size:13px; color:#4b5563; line-height:1.6; }

        .rp-confidence { margin-bottom:12px; }
        .rp-chart-box { background:rgba(13,27,38,.5); border:1px solid var(--c-border-4); border-radius:14px; padding:16px; }
        .rp-chart-legend { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-top:10px; font-size:12px; color:#cbd5e1; }
        .rp-chart-foot { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-top:12px; padding-top:10px; border-top:1px solid rgba(55,65,81,.3); font-size:12px; color:#9ca3af; }

        .rp-summary { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:14px 18px 4px; }
        .rp-range { font-size:13px; font-weight:500; color:#e5e7eb; }

        /* Onglet « Détail du scoring » : un tableau par jour, paramètres en
           lignes, heures en colonnes, cellule = pastille colorée. */
        .rp-voting { padding:14px 16px 18px; }
        .rp-voting-day { margin-bottom:18px; }
        .rp-voting-day:last-of-type { margin-bottom:8px; }
        .rp-voting-daylabel { display:flex; align-items:baseline; gap:8px; font-size:13px; font-weight:600; color:#e5e7eb; margin-bottom:6px; padding:0 2px; }
        .rp-voting-dayhint  { font-size:11px; color:#6b7280; font-weight:400; font-family:var(--font-mono); }
        /* Wrapper scrollable du tableau de scoring : sur mobile le tableau
           dépasse facilement la largeur du volet (13 colonnes d'heures +
           colonne paramètre + white-space:nowrap). On rend la scrollbar
           horizontale clairement visible (couleur sky) au lieu des 3px
           génériques quasi invisibles, pour que l'utilisateur sache
           qu'il peut scroller. */
        .rp-voting-tablewrap { overflow-x:auto; background:rgba(13,27,38,.4); border:1px solid var(--c-border-4); border-radius:10px;
            scrollbar-color:#38bdf8 rgba(55,65,81,.3);
            scrollbar-width:thin; }
        .rp-voting-tablewrap::-webkit-scrollbar { height:8px; width:8px; }
        .rp-voting-tablewrap::-webkit-scrollbar-track { background:rgba(55,65,81,.3); border-radius:4px; }
        .rp-voting-tablewrap::-webkit-scrollbar-thumb { background:#38bdf8; border-radius:4px; }
        .rp-voting-tablewrap::-webkit-scrollbar-thumb:hover { background:#0ea5e9; }
        .rp-voting-table { border-collapse:separate; border-spacing:0; width:100%; font-size:11px; }
        .rp-voting-table th, .rp-voting-table td { padding:5px 4px; text-align:center; white-space:nowrap; }
        .rp-voting-th-param { text-align:left !important; padding-left:12px !important; color:#9ca3af; font-weight:500; font-size:11px; border-bottom:1px solid var(--c-border-5); background:rgba(17,24,39,.6); }
        .rp-voting-th-hour  { font-family:var(--font-mono); color:#9ca3af; font-weight:500; border-bottom:1px solid var(--c-border-5); background:rgba(17,24,39,.6); }
        .rp-voting-td-param { text-align:left !important; padding-left:12px !important; color:#cbd5e1; font-weight:500; border-bottom:1px solid var(--c-border-25); }
        .rp-voting-cell     { border-bottom:1px solid var(--c-border-25); }
        .rp-voting-table tbody tr:last-child td { border-bottom:none; }
        .rp-voting-status td { background:rgba(17,24,39,.5); font-weight:600; color:#e5e7eb; }
        /* Variables des couleurs voting — réutilisées par les cellules split
           diagonales (cf. linear-gradient inline dans le template).
           --rp-v-sep : couleur de la barre diagonale ; volontairement
           contrastée (blanche) pour rester visible même quand les deux
           triangles ont la même couleur (perso applique = même résultat
           que global). C'est le signal visuel « ton scoring est en jeu ». */
        .rp-voting { --rp-v-green:#22c55e; --rp-v-orange:#f59e0b; --rp-v-red:#ef4444; --rp-v-na:rgba(75,85,99,.5); --rp-v-sep:#ffffff; }
        .rp-voting-dot { display:inline-block; width:14px; height:14px; border-radius:3px; vertical-align:middle; cursor:default; }
        .rp-voting-green  { background:#22c55e; }
        .rp-voting-orange { background:#f59e0b; }
        .rp-voting-red    { background:#ef4444; }
        .rp-voting-na     { background:rgba(75,85,99,.5); }
        /* Cellule split diagonale : background construit en gradient inline.
           Petit liseré pour distinguer la diagonale même quand les couleurs
           sont proches. */
        .rp-voting-split { box-shadow:inset 0 0 0 1px rgba(15,23,42,.6); }
        .rp-voting-legend { display:flex; flex-wrap:wrap; gap:14px; padding:10px 16px; border-top:1px solid var(--c-border-4); font-size:11px; color:#9ca3af; }

        /* Tooltip flottant pour les cellules de l'onglet « Détail scoring ».
           Position en pixels absolus (calculée depuis le viewport). */
        .rp-voting-tip { position:fixed; transform:translate(-50%, -100%); z-index:80; background:var(--c-bg-dark); border:1px solid #334155; color:#e5e7eb; font-size:11px; line-height:1.5; padding:6px 10px; border-radius:6px; box-shadow:0 8px 20px rgba(0,0,0,.5); pointer-events:none; white-space:nowrap; font-family:'DM Sans',sans-serif; }
        .rp-voting-tip > div + div { margin-top:2px; padding-top:2px; border-top:1px dashed rgba(148,163,184,.25); }
        .rp-voting-legend span { display:inline-flex; align-items:center; gap:6px; }

        /* Volet balise — bloc rose des vents en 3 colonnes :
           dernier relevé · cadran · légende.
           (mise en page en classe et non en style inline car Alpine x-show
           écrase un `display` inline en repassant l'élément en block) */
        .rp-balise-rose { display:flex; align-items:center; gap:18px; flex-wrap:nowrap; padding:12px 18px; }
        .rp-balise-now  { flex:0 0 138px; }
        .rp-balise-dial { flex:1 1 280px; min-width:0; }
        .rp-balise-dial svg { display:block; width:100%; height:auto; overflow:visible; }
        .rp-balise-leg  { flex:0 1 230px; min-width:0; font-size:12px; color:#9ca3af; line-height:1.6; }

        /* Le sélecteur de jour vit dans la 1ère .lp-section de l'onglet
           Paramètres (cf. left-panel.blade.php). Le bouton flottant qui
           était sur la carte a été supprimé (problèmes de positionnement
           en paysage + chevauchement contrôles Leaflet). */
