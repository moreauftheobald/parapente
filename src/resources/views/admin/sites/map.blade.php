@extends('layouts.admin')
@section('title', 'Carte des sites')

@push('styles')
<style>
    #sites-map { width:800px; height:800px; background:#0f172a; }
    .leaflet-container { font-family:'DM Sans',sans-serif; }
    .pg-pin svg { filter:drop-shadow(0 2px 4px rgba(0,0,0,.6)); transition:transform .12s; }
    .pg-pin:hover svg { transform:scale(1.18); }
</style>
@endpush

@section('content')
<div class="flex flex-col gap-3">
    {{-- En-tête --}}
    <div class="flex items-baseline justify-between shrink-0">
        <div>
            <h1 class="text-2xl font-semibold text-white">Carte des sites</h1>
            <p class="text-sm text-gray-500 mt-1">
                <span id="active-count" class="text-emerald-400 font-medium">{{ $activeCount }}</span> actif{{ $activeCount > 1 ? 's' : '' }}
                · {{ $totalCount }} au total · <span class="text-gray-400">clic sur un marqueur = activer / désactiver</span>
            </p>
        </div>
        <a href="{{ route('admin.sites.index') }}" class="text-xs text-gray-400 hover:text-white transition">
            <i class="fa-solid fa-table-list"></i> Vue liste
        </a>
    </div>

    {{-- Carte (taille fixe) + panneau latéral --}}
    <div class="flex gap-3 items-start">
        <div id="sites-map" class="shrink-0 rounded-xl border border-gray-800 overflow-hidden"></div>

        <aside class="w-80 shrink-0 bg-gray-900 border border-gray-800 rounded-xl overflow-y-auto p-5 flex flex-col" style="height:800px;">
            <div id="panel-empty" class="m-auto text-center text-gray-600">
                <i class="fa-regular fa-hand-pointer text-3xl mb-3 block"></i>
                <p class="text-sm">Sélectionne un site sur la carte<br>pour voir ses informations.</p>
                <div class="mt-6 text-left text-xs space-y-2">
                    <div class="flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full bg-emerald-500"></span> Site actif</div>
                    <div class="flex items-center gap-2"><span class="inline-block w-3 h-3 rounded-full bg-gray-500"></span> Site inactif</div>
                </div>
            </div>

            <div id="panel-content" class="hidden">
                <div class="flex items-start justify-between gap-2">
                    <h2 id="p-name" class="text-lg font-semibold text-white leading-tight"></h2>
                    <span id="p-state" class="shrink-0 text-[10px] uppercase tracking-wider px-2 py-0.5 rounded-full border"></span>
                </div>
                <div id="p-badges" class="flex flex-wrap gap-1.5 mt-2"></div>

                <dl class="mt-4 text-sm space-y-1.5">
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Altitude</dt><dd id="p-alt" class="text-gray-200 font-mono"></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Coordonnées</dt><dd id="p-coords" class="text-gray-200 font-mono text-xs self-center"></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Axe de vent</dt><dd id="p-windaxis" class="text-gray-200 font-mono"></dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-gray-500">Vent km/h</dt><dd id="p-windspeed" class="text-gray-200 font-mono text-xs self-center"></dd></div>
                </dl>

                <p id="p-desc" class="mt-4 text-xs text-gray-400 whitespace-pre-wrap leading-relaxed border-t border-gray-800 pt-3 hidden"></p>

                <div class="mt-auto pt-5 space-y-2">
                    <button type="button" id="p-toggle"
                            class="w-full py-2.5 text-sm font-medium rounded-md transition flex items-center justify-center gap-2"></button>
                    <div class="flex gap-2">
                        <a id="p-edit" href="#" class="flex-1 text-center py-2 text-xs bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-md transition">
                            <i class="fa-solid fa-pen-to-square"></i> Éditer
                        </a>
                        <a id="p-gmaps" href="#" target="_blank" rel="noopener" class="flex-1 text-center py-2 text-xs bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-md transition">
                            <i class="fa-brands fa-google"></i> Maps
                        </a>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
(function () {
    const SITES = @json($sites);
    const CSRF  = document.querySelector('meta[name="csrf-token"]').content;

    function pinSvg(active) {
        const c = active ? '#22c55e' : '#6b7280';
        return '<div class="pg-pin"><svg width="30" height="38" viewBox="0 0 30 38" xmlns="http://www.w3.org/2000/svg">'
            + '<path d="M15 0C6.7 0 0 6.7 0 15c0 10.5 13.4 21.8 14 22.3.6.5 1.4-.8 2-1.3C16.6 35.5 30 25 30 15 30 6.7 23.3 0 15 0z" fill="' + c + '" stroke="#fff" stroke-width="2"/>'
            + '<circle cx="15" cy="15" r="6" fill="#ffffff" fill-opacity="0.9"/></svg></div>';
    }
    function makeIcon(active) {
        return L.divIcon({ className: '', html: pinSvg(active), iconSize: [30, 38], iconAnchor: [15, 38] });
    }

    function init() {
        if (typeof L === 'undefined') return setTimeout(init, 60);
        const el = document.getElementById('sites-map');
        if (!el) return;

        const map = L.map(el, { center: [47.5, 4.5], zoom: 6 });
        L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap, © OpenTopoMap', maxZoom: 17,
        }).addTo(map);

        const markers = {};
        const bounds  = [];
        const $empty   = document.getElementById('panel-empty');
        const $content = document.getElementById('panel-content');
        const $toggle  = document.getElementById('p-toggle');
        let current = null;
        let busy    = false;

        function renderState(s) {
            const $st = document.getElementById('p-state');
            if (s.active) {
                $st.textContent = 'Actif';
                $st.className = 'shrink-0 text-[10px] uppercase tracking-wider px-2 py-0.5 rounded-full border bg-emerald-500/15 border-emerald-500/40 text-emerald-300';
                $toggle.className = 'w-full py-2.5 text-sm font-medium rounded-md transition flex items-center justify-center gap-2 bg-gray-700 hover:bg-gray-600 text-gray-200';
                $toggle.innerHTML = '<i class="fa-solid fa-power-off"></i> Désactiver ce site';
            } else {
                $st.textContent = 'Inactif';
                $st.className = 'shrink-0 text-[10px] uppercase tracking-wider px-2 py-0.5 rounded-full border bg-gray-700/40 border-gray-600 text-gray-400';
                $toggle.className = 'w-full py-2.5 text-sm font-medium rounded-md transition flex items-center justify-center gap-2 bg-emerald-500 hover:bg-emerald-400 text-white';
                $toggle.innerHTML = '<i class="fa-solid fa-check"></i> Activer ce site';
            }
        }

        function selectSite(s) {
            current = s;
            $empty.classList.add('hidden');
            $content.classList.remove('hidden');
            document.getElementById('p-name').textContent = s.name;
            const badges = ['<span class="text-[10px] px-1.5 py-0.5 rounded bg-sky-500/15 text-sky-300 border border-sky-500/30">' + (s.source || '—') + '</span>'];
            if (s.level)  badges.push('<span class="text-[10px] px-1.5 py-0.5 rounded bg-violet-500/15 text-violet-300 border border-violet-500/30">' + s.level + '</span>');
            if (s.region) badges.push('<span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-700/50 text-gray-300 border border-gray-600">' + s.region + '</span>');
            document.getElementById('p-badges').innerHTML = badges.join('');
            document.getElementById('p-alt').textContent       = (s.altitude_m != null ? s.altitude_m + ' m' : '—');
            document.getElementById('p-coords').textContent    = s.lat.toFixed(5) + ', ' + s.lng.toFixed(5);
            document.getElementById('p-windaxis').textContent  = s.conditions ? (s.conditions.wind_dir_min + '° – ' + s.conditions.wind_dir_max + '°') : '—';
            document.getElementById('p-windspeed').textContent = s.conditions ? (s.conditions.wind_speed_min + '–' + s.conditions.wind_speed_max + ' (idéal ' + s.conditions.wind_speed_ideal + ')') : '—';
            const $desc = document.getElementById('p-desc');
            if (s.description) { $desc.textContent = s.description; $desc.classList.remove('hidden'); }
            else $desc.classList.add('hidden');
            document.getElementById('p-edit').href  = s.edit_url;
            document.getElementById('p-gmaps').href = 'https://www.google.com/maps/place/' + s.lat + ',' + s.lng;
            renderState(s);
        }

        function updateActiveCounter() {
            document.getElementById('active-count').textContent = SITES.filter(x => x.active).length;
        }

        function toggleSite(s) {
            if (busy) return;
            busy = true;
            fetch(s.toggle_url, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } })
                .then(r => r.ok ? r.json() : Promise.reject(r))
                .then(data => {
                    s.active = data.active;
                    markers[s.id].setIcon(makeIcon(s.active));
                    if (current && current.id === s.id) renderState(s);
                    updateActiveCounter();
                })
                .catch(() => alert('Échec de la mise à jour. Recharge la page.'))
                .finally(() => { busy = false; });
        }

        SITES.forEach(function (s) {
            const m = L.marker([s.lat, s.lng], { icon: makeIcon(s.active) })
                .bindTooltip(s.name, { direction: 'top', offset: [0, -34] })
                .addTo(map);
            m.on('click', function () { selectSite(s); toggleSite(s); });
            markers[s.id] = m;
            bounds.push([s.lat, s.lng]);
        });
        if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 11 });

        $toggle.addEventListener('click', function () { if (current) toggleSite(current); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>
@endsection
