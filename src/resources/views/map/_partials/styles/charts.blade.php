        /* Sections de graphes dans le panel */
        .chart-section { padding:0 18px; border-bottom:1px solid var(--c-border-25); }
        .chart-section:last-child { border-bottom:none; padding-bottom:14px; }
        .chart-header { display:flex; justify-content:space-between; align-items:baseline; padding:14px 0 6px; cursor:pointer; user-select:none; transition:opacity .15s; }
        .chart-header:hover { opacity:.85; }
        .chart-title { font-size:12px; color:#e5e7eb; font-weight:600; text-transform:uppercase; letter-spacing:.06em; }
        .chart-unit { font-size:11px; color:#cbd5e1; font-family:var(--font-mono); margin-left:8px; }
        .chart-toggle { font-size:13px; color:#9ca3af; transition:transform .2s; line-height:1; }
        .chart-toggle.collapsed { transform:rotate(-90deg); }
        .chart-svg-wrap { overflow:hidden; transition:max-height .25s ease-out; max-height:200px; }
        .chart-svg-wrap.collapsed { max-height:0; }
        .chart-svg { display:block; width:100%; height:120px; overflow:visible; }
