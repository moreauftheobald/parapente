        .dd-trigger { display:flex; align-items:center; gap:10px; padding:8px 14px; border-radius:12px; cursor:pointer; background:#1f2937; border:1px solid rgba(75,85,99,.5); color:#e5e7eb; font-size:14px; transition:border-color .15s; }
        .dd-trigger:hover { border-color:rgba(156,163,175,.6); }
        .dd-arrow { font-size:10px; color:#6b7280; display:inline-block; transition:transform .2s; }
        .dd-arrow.open { transform:rotate(180deg); }
        .dd-menu { position:fixed; background:#111827; border:1px solid rgba(55,65,81,.7); border-radius:16px; box-shadow:0 24px 48px rgba(0,0,0,.85); padding:6px 0; z-index:99999; }
        .dd-item { display:flex; align-items:center; gap:12px; padding:10px 16px; cursor:pointer; transition:background .1s; font-size:13px; color:#9ca3af; width:100%; background:none; border:none; text-align:left; }
        .dd-item:hover { background:rgba(255,255,255,.06); color:#e5e7eb; }
        .dd-item.is-active { background:rgba(255,255,255,.1); color:#fff; }
        .dd-sub { font-size:11px; color:#4b5563; }
