        /* Side panel */
        :root { --panel-width: clamp(600px, 50vw, 900px); }
        #panel { width:var(--panel-width); transition:transform .35s cubic-bezier(.4,0,.2,1); transform:translateX(100%); }
        #panel.open { transform:translateX(0); }

        .panel-header { padding:18px 20px 0; border-bottom:1px solid rgba(55,65,81,.4); flex-shrink:0; }
        .panel-titlebar { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
        .panel-titlebar h2 { font-size:16px; font-weight:600; color:#fff; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .panel-meta { display:flex; align-items:center; gap:8px; margin-top:6px; font-size:12px; color:#6b7280; font-family:'DM Mono',monospace; }
        .panel-meta .sep { color:#374151; }
        .panel-close { width:28px; height:28px; border-radius:50%; border:none; background:transparent; color:#6b7280; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all .15s; }
        .panel-close:hover { background:rgba(55,65,81,.7); color:#fff; }
        .panel-sun { margin-top:10px; display:flex; align-items:center; gap:8px; font-size:12px; color:#9ca3af; }
        .panel-sun .ico { color:#fbbf24; font-size:18px; line-height:1; }
        .panel-sun .mono { color:#e5e7eb; font-family:'DM Mono',monospace; }

        .panel-tabs { display:flex; gap:4px; margin-top:14px; }
        .pg-tab { padding:9px 16px; border:none; background:transparent; color:#6b7280; cursor:pointer; font-size:13px; font-weight:500; border-bottom:2px solid transparent; transition:color .15s,border-color .15s; }
        .pg-tab:hover:not(.active) { color:#9ca3af; }
        .pg-tab.active { color:#fff; border-bottom-color:#38bdf8; }

        .panel-day-row { display:flex; align-items:center; gap:10px; padding:14px 0 16px; }
        .pg-day-arrow { width:30px; height:30px; border-radius:50%; border:1px solid rgba(55,65,81,.5); background:rgba(31,41,55,.4); color:#9ca3af; cursor:pointer; font-size:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:all .15s; }
        .pg-day-arrow:hover:not(:disabled) { color:#fff; border-color:rgba(75,85,99,.7); background:rgba(31,41,55,.7); }
        .pg-day-arrow:disabled { opacity:.35; cursor:not-allowed; }
        .panel-day-label { flex:1; text-align:center; font-size:14px; font-weight:500; color:#fff; }
        .panel-conformity { display:flex; align-items:center; gap:8px; font-size:11px; color:#6b7280; }
        .panel-conformity .bar { width:64px; height:5px; background:#1f2937; border-radius:3px; overflow:hidden; }
        .panel-conformity .fill { height:100%; background:#22c55e; transition:width .4s; }
        .panel-conformity .val { font-family:'DM Mono',monospace; color:#e5e7eb; min-width:32px; text-align:right; }

        .panel-legend { padding:10px 20px; background:rgba(17,24,39,.95); border-bottom:1px solid rgba(55,65,81,.4); display:flex; flex-wrap:wrap; gap:6px; flex-shrink:0; position:sticky; top:0; z-index:5; backdrop-filter:blur(6px); }
        .pg-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background:rgba(31,41,55,.6); border:1px solid rgba(55,65,81,.5); font-size:12px; color:#e5e7eb; user-select:none; }
        .pg-chip-dot { width:9px; height:3px; border-radius:1px; flex-shrink:0; }
        .pg-chip-consensus { background:rgba(255,255,255,.06); border-color:rgba(255,255,255,.18); color:#fff; font-weight:500; }
        .pg-chip-consensus .pg-chip-dot { background-image:repeating-linear-gradient(90deg,#fff 0 3px,transparent 3px 6px); height:2px; }

        .panel-loader { flex:1; display:flex; align-items:center; justify-content:center; }
        .panel-spinner { width:28px; height:28px; border:2px solid #374151; border-top-color:#38bdf8; border-radius:50%; animation:spin 1s linear infinite; }

        .panel-scroll { flex:1; overflow-y:auto; min-height:0; }
        .panel-placeholder { padding:60px 24px; text-align:center; font-size:13px; color:#4b5563; line-height:1.6; }
        .panel-placeholder strong { color:#9ca3af; font-weight:600; }

        .panel-footer { padding:10px 20px; border-top:1px solid rgba(55,65,81,.4); flex-shrink:0; display:flex; justify-content:space-between; font-size:10px; color:#374151; }
