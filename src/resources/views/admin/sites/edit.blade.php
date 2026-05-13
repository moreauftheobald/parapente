@extends('layouts.admin')
@section('title', 'Édition · ' . $site->name)

@php
    $cond = $site->conditions;
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $labelCls = 'block text-xs font-medium text-gray-400 mb-1';
    $errorCls = 'text-red-400 text-xs mt-1';
@endphp

@section('content')
<div class="max-w-5xl">
    {{-- Bandeau titre ────────────────────────────────────────────── --}}
    <div class="bg-gradient-to-r from-sky-500/10 via-gray-900 to-gray-900 border border-sky-500/20 rounded-xl p-5 mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
                <i class="fa-solid fa-mountain-sun text-sky-400"></i>
                {{ $site->name }}
            </h1>
            <p class="text-xs text-gray-500 mt-1 font-mono">
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">{{ $site->source }}</span>
                @if ($site->external_id)
                    <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">#{{ $site->external_id }}</span>
                @endif
                <span class="text-gray-600">· créé le {{ $site->created_at->format('d/m/Y') }}</span>
            </p>
        </div>
        <a href="{{ route('admin.sites.index', request()->only(['source','active','level','region','search','sort','dir','page'])) }}"
           class="text-sm text-gray-400 hover:text-white transition flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> Retour à la liste
        </a>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded bg-red-500/15 border border-red-500/30 text-red-300 text-sm">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.sites.update', $site) }}">
        @csrf
        @method('PATCH')

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
            {{-- Section Site ──────────────────────────────────────── --}}
            <div class="bg-gray-900 border border-sky-500/20 rounded-xl p-5">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-sky-500/20">
                    <span class="w-8 h-8 rounded-lg bg-sky-500/15 border border-sky-500/30 flex items-center justify-center text-sky-300">
                        <i class="fa-solid fa-mountain-sun"></i>
                    </span>
                    <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider">Identité</h2>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="{{ $labelCls }}">Nom <span class="text-red-400">*</span></label>
                        <input name="name" type="text" required class="{{ $inputCls }}" value="{{ old('name', $site->name) }}">
                        @error('name')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label class="{{ $labelCls }}">Région</label>
                        <input name="region" type="text" class="{{ $inputCls }}" value="{{ old('region', $site->region) }}" placeholder="ex: grand-est">
                    </div>
                    <div>
                        <label class="{{ $labelCls }}">Niveau requis <span class="text-red-400">*</span></label>
                        <select name="level" required class="{{ $inputCls }}">
                            @foreach (['debutant'=>'Débutant','intermediaire'=>'Intermédiaire','confirme'=>'Confirmé'] as $val => $lbl)
                                <option value="{{ $val }}" @selected(old('level', $site->level) === $val)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="{{ $labelCls }}">Description</label>
                        <textarea name="description" rows="3" class="{{ $inputCls }}">{{ old('description', $site->description) }}</textarea>
                    </div>

                    <div class="sm:col-span-2 mt-1 p-3 rounded-lg
                        @class([
                            'bg-emerald-500/10 border border-emerald-500/30' => old('active', $site->active),
                            'bg-gray-950 border border-gray-700' => ! old('active', $site->active),
                        ])">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="hidden" name="active" value="0">
                            <input name="active" type="checkbox" value="1" @checked(old('active', $site->active))
                                   class="w-4 h-4 rounded border-gray-700 bg-gray-950 text-emerald-500 focus:ring-emerald-500/40">
                            <span class="text-sm
                                @class([
                                    'text-emerald-300 font-medium' => old('active', $site->active),
                                    'text-gray-400' => ! old('active', $site->active),
                                ])">
                                <i class="fa-solid {{ old('active', $site->active) ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
                                {{ old('active', $site->active) ? 'Site actif' : 'Site inactif' }}
                                <span class="text-xs text-gray-500 ml-1">(visible carte + fetch météo)</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Section Coordonnées ───────────────────────────────── --}}
            <div class="bg-gray-900 border border-violet-500/20 rounded-xl p-5">
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-violet-500/20">
                    <span class="w-8 h-8 rounded-lg bg-violet-500/15 border border-violet-500/30 flex items-center justify-center text-violet-300">
                        <i class="fa-solid fa-location-dot"></i>
                    </span>
                    <h2 class="text-sm font-semibold text-violet-200 uppercase tracking-wider">Coordonnées</h2>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="{{ $labelCls }}"><i class="fa-solid fa-circle text-emerald-400 mr-1 text-[8px]"></i> Lat. décollage <span class="text-red-400">*</span></label>
                        <input id="input-lat" name="latitude" type="number" step="0.000001" required class="{{ $inputCls }} font-mono"
                               value="{{ old('latitude', $site->latitude) }}">
                        @error('latitude')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="{{ $labelCls }}"><i class="fa-solid fa-circle text-emerald-400 mr-1 text-[8px]"></i> Lng. décollage <span class="text-red-400">*</span></label>
                        <input id="input-lng" name="longitude" type="number" step="0.000001" required class="{{ $inputCls }} font-mono"
                               value="{{ old('longitude', $site->longitude) }}">
                        @error('longitude')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                    </div>

                    <div class="col-span-2">
                        <label class="{{ $labelCls }}">Altitude (m)</label>
                        <input name="altitude_m" type="number" step="1" min="0" max="9000" class="{{ $inputCls }} font-mono"
                               value="{{ old('altitude_m', $site->altitude_m) }}">
                    </div>

                    <div>
                        <label class="{{ $labelCls }}"><i class="fa-solid fa-circle text-amber-400 mr-1 text-[8px]"></i> Lat. atterrissage</label>
                        <input id="input-landing-lat" name="landing_lat" type="number" step="0.000001" class="{{ $inputCls }} font-mono"
                               value="{{ old('landing_lat', $site->landing_lat) }}">
                    </div>
                    <div>
                        <label class="{{ $labelCls }}"><i class="fa-solid fa-circle text-amber-400 mr-1 text-[8px]"></i> Lng. atterrissage</label>
                        <input id="input-landing-lng" name="landing_lng" type="number" step="0.000001" class="{{ $inputCls }} font-mono"
                               value="{{ old('landing_lng', $site->landing_lng) }}">
                    </div>
                </div>

                {{-- Boutons d'action carte --}}
                <div class="flex flex-wrap gap-2 mb-3">
                    <button type="button" id="btn-geolocate"
                            class="px-3 py-1.5 text-xs bg-violet-500/15 border border-violet-500/30 text-violet-200 hover:bg-violet-500/25 rounded-md transition flex items-center gap-1">
                        <i class="fa-solid fa-location-crosshairs"></i> Géolocaliser
                    </button>
                    <button type="button" id="btn-recenter"
                            class="px-3 py-1.5 text-xs bg-gray-800 border border-gray-700 text-gray-300 hover:bg-gray-700 rounded-md transition flex items-center gap-1">
                        <i class="fa-solid fa-crosshairs"></i> Recentrer
                    </button>
                    <button type="button" id="btn-add-landing"
                            class="px-3 py-1.5 text-xs bg-amber-500/15 border border-amber-500/30 text-amber-200 hover:bg-amber-500/25 rounded-md transition flex items-center gap-1"
                            @if($site->landing_lat) style="display:none" @endif>
                        <i class="fa-solid fa-plus"></i> Ajouter atterrissage
                    </button>
                    <a href="https://www.google.com/maps/place/{{ $site->latitude }},{{ $site->longitude }}"
                       target="_blank" rel="noopener"
                       class="ml-auto inline-flex items-center gap-1 px-3 py-1.5 text-xs text-violet-300 hover:text-violet-200 transition">
                        <i class="fa-brands fa-google"></i> Google Maps
                    </a>
                </div>

                {{-- Mini-carte Leaflet --}}
                <div id="site-picker-map" class="rounded-lg border border-gray-700 overflow-hidden" style="height:260px;"></div>
                <p class="text-xs text-gray-500 mt-2">
                    <i class="fa-solid fa-circle-info"></i>
                    Glisser les marqueurs pour ajuster la position. Vert = décollage, orange = atterrissage.
                </p>
            </div>
        </div>

        {{-- Section Conditions de vol ───────────────────────────── --}}
        <div class="bg-gray-900 border border-emerald-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-emerald-500/20">
                <span class="w-8 h-8 rounded-lg bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-300">
                    <i class="fa-solid fa-wind"></i>
                </span>
                <h2 class="text-sm font-semibold text-emerald-200 uppercase tracking-wider">Conditions de vol favorables</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                {{-- Ligne 1 : direction min, max, hint --}}
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-compass text-emerald-400 mr-1"></i> Direction min (°) <span class="text-red-400">*</span></label>
                    <input name="wind_dir_min" type="number" min="0" max="360" required class="{{ $inputCls }} font-mono"
                           value="{{ old('wind_dir_min', $cond?->wind_dir_min ?? 0) }}">
                </div>
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-compass text-emerald-400 mr-1"></i> Direction max (°) <span class="text-red-400">*</span></label>
                    <input name="wind_dir_max" type="number" min="0" max="360" required class="{{ $inputCls }} font-mono"
                           value="{{ old('wind_dir_max', $cond?->wind_dir_max ?? 360) }}">
                </div>
                <div class="text-xs text-gray-500 self-end pb-2">
                    <i class="fa-solid fa-circle-info"></i> Convention météo (FROM). Si min &gt; max, la plage chevauche le Nord.
                </div>

                {{-- Ligne 2 : vitesses --}}
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-wind text-emerald-400 mr-1"></i> Vitesse min</label>
                    <div class="flex">
                        <input name="wind_speed_min" type="number" step="0.1" min="0" max="100" required class="{{ $inputCls }} font-mono rounded-r-none"
                               value="{{ old('wind_speed_min', $cond?->wind_speed_min ?? 0) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">km/h</span>
                    </div>
                </div>
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-wind text-emerald-400 mr-1"></i> Vitesse max</label>
                    <div class="flex">
                        <input name="wind_speed_max" type="number" step="0.1" min="0" max="100" required class="{{ $inputCls }} font-mono rounded-r-none"
                               value="{{ old('wind_speed_max', $cond?->wind_speed_max ?? 25) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">km/h</span>
                    </div>
                </div>
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-bullseye text-emerald-400 mr-1"></i> Vitesse idéale</label>
                    <div class="flex">
                        <input name="wind_speed_ideal" type="number" step="0.1" min="0" max="100" required class="{{ $inputCls }} font-mono rounded-r-none"
                               value="{{ old('wind_speed_ideal', $cond?->wind_speed_ideal ?? 12) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">km/h</span>
                    </div>
                </div>

                {{-- Ligne 3 : rafales (surcharges optionnelles par site) + plafond + couverture --}}
                {{-- Précipitations : seuils globaux (cf. /admin/settings), plus de précip_max par site --}}
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-cloud text-gray-400 mr-1"></i> Plafond min</label>
                    <div class="flex">
                        <input name="cloud_base_min_m" type="number" min="0" max="5000" required class="{{ $inputCls }} font-mono rounded-r-none"
                               value="{{ old('cloud_base_min_m', $cond?->cloud_base_min_m ?? 800) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">m</span>
                    </div>
                </div>
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-cloud-sun text-amber-300 mr-1"></i> Couv. nuages basse max</label>
                    <div class="flex">
                        <input name="cloud_cover_low_max" type="number" min="0" max="100" required class="{{ $inputCls }} font-mono rounded-r-none"
                               value="{{ old('cloud_cover_low_max', $cond?->cloud_cover_low_max ?? 50) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">%</span>
                    </div>
                </div>

                {{-- Surcharge rafales (laisser vide pour utiliser les seuils globaux) --}}
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-tornado text-amber-400 mr-1"></i> Rafale orange <span class="text-gray-600">(override)</span></label>
                    <div class="flex">
                        <input name="wind_gust_orange_kmh" type="number" step="0.1" min="0" max="200" class="{{ $inputCls }} font-mono rounded-r-none"
                               placeholder="globale"
                               value="{{ old('wind_gust_orange_kmh', $cond?->wind_gust_orange_kmh) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">km/h</span>
                    </div>
                </div>
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-tornado text-red-400 mr-1"></i> Rafale rouge <span class="text-gray-600">(override)</span></label>
                    <div class="flex">
                        <input name="wind_gust_red_kmh" type="number" step="0.1" min="0" max="200" class="{{ $inputCls }} font-mono rounded-r-none"
                               placeholder="globale"
                               value="{{ old('wind_gust_red_kmh', $cond?->wind_gust_red_kmh) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">km/h</span>
                    </div>
                </div>
                <div class="text-xs text-gray-500 self-end pb-2 md:col-span-3">
                    <i class="fa-solid fa-circle-info"></i> Les seuils de pluie sont globaux ; les seuils de rafales aussi
                    sauf surcharge ci-dessus (laisser vide pour utiliser la valeur globale).
                    <a href="{{ route('admin.settings.index') }}" class="text-sky-400 hover:text-sky-300">Paramètres généraux</a>
                </div>
            </div>

            <div class="mt-3">
                <label class="{{ $labelCls }}"><i class="fa-solid fa-pen-to-square text-gray-400 mr-1"></i> Notes</label>
                <textarea name="notes" rows="2" class="{{ $inputCls }}">{{ old('notes', $cond?->notes) }}</textarea>
            </div>
        </div>

        {{-- Actions ─────────────────────────────────────────────── --}}
        <div class="flex items-center justify-between mb-8">
            <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-sky-500 to-sky-600 hover:from-sky-400 hover:to-sky-500 text-white text-sm font-medium rounded-md transition shadow-lg shadow-sky-500/20">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer
            </button>
            <a href="{{ route('admin.sites.index') }}" class="text-sm text-gray-400 hover:text-white transition">
                Annuler
            </a>
        </div>
    </form>

    {{-- Zone dangereuse ─────────────────────────────────────────── --}}
    <div class="bg-red-500/5 border border-red-500/30 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-red-300 mb-2 flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> Zone dangereuse
        </h3>
        <div class="flex items-center justify-between">
            <p class="text-xs text-gray-500 max-w-md">
                Suppression du site et de toutes ses données associées (conditions, forecasts, scores).
                <strong class="text-red-400">Irréversible.</strong>
            </p>
            <form method="POST" action="{{ route('admin.sites.destroy', $site) }}"
                  onsubmit="return confirm('Supprimer définitivement « {{ addslashes($site->name) }} » ?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-4 py-2 bg-red-500/15 border border-red-500/40 text-red-300 hover:bg-red-500/25 hover:text-red-200 text-sm rounded-md transition flex items-center gap-2">
                    <i class="fa-solid fa-trash"></i> Supprimer
                </button>
            </form>
        </div>
    </div>
</div>

{{-- ───────────────────────────────────────────────────────────
     Picker carte Leaflet (décollage + atterrissage draggables)
     Sync bidirectionnel marqueurs ↔ inputs lat/lng.
     L est exposé globalement par resources/js/app.js (Vite).
─────────────────────────────────────────────────────────────── --}}
<script>
(function () {
    function init() {
        if (typeof L === 'undefined') return setTimeout(init, 60);

        const mapEl = document.getElementById('site-picker-map');
        if (!mapEl) return;

        const $latIn        = document.getElementById('input-lat');
        const $lngIn        = document.getElementById('input-lng');
        const $landingLatIn = document.getElementById('input-landing-lat');
        const $landingLngIn = document.getElementById('input-landing-lng');
        const $btnGeo       = document.getElementById('btn-geolocate');
        const $btnRecenter  = document.getElementById('btn-recenter');
        const $btnAddLanding = document.getElementById('btn-add-landing');

        const initLat = parseFloat($latIn.value) || 49;
        const initLng = parseFloat($lngIn.value) || 6;

        const map = L.map(mapEl, { center: [initLat, initLng], zoom: 14 });
        L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors, © OpenTopoMap',
            maxZoom: 17,
        }).addTo(map);

        // Marqueur décollage (vert)
        const takeoffIcon = L.divIcon({
            className: '',
            html: '<div style="width:28px;height:28px;border-radius:50%;background:#22c55e;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:13px;">D</div>',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
        });
        const takeoffMarker = L.marker([initLat, initLng], { draggable: true, icon: takeoffIcon })
            .bindTooltip('Décollage', { permanent: false, direction: 'top', offset: [0, -16] })
            .addTo(map);

        // Marqueur atterrissage (orange) — créé seulement si coords présentes
        let landingMarker = null;
        const landingIcon = L.divIcon({
            className: '',
            html: '<div style="width:24px;height:24px;border-radius:50%;background:#f59e0b;border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:11px;">A</div>',
            iconSize: [24, 24],
            iconAnchor: [12, 12],
        });

        function ensureLandingMarker(lat, lng) {
            if (landingMarker) {
                landingMarker.setLatLng([lat, lng]);
            } else {
                landingMarker = L.marker([lat, lng], { draggable: true, icon: landingIcon })
                    .bindTooltip('Atterrissage', { permanent: false, direction: 'top', offset: [0, -14] })
                    .addTo(map);
                landingMarker.on('dragend', () => {
                    const ll = landingMarker.getLatLng();
                    $landingLatIn.value = ll.lat.toFixed(6);
                    $landingLngIn.value = ll.lng.toFixed(6);
                });
                if ($btnAddLanding) $btnAddLanding.style.display = 'none';
            }
        }

        if ($landingLatIn.value && $landingLngIn.value) {
            ensureLandingMarker(parseFloat($landingLatIn.value), parseFloat($landingLngIn.value));
        }

        // Drag décollage → update inputs
        takeoffMarker.on('dragend', () => {
            const ll = takeoffMarker.getLatLng();
            $latIn.value = ll.lat.toFixed(6);
            $lngIn.value = ll.lng.toFixed(6);
        });

        // Inputs → marqueurs
        function syncTakeoffFromInputs() {
            const lat = parseFloat($latIn.value), lng = parseFloat($lngIn.value);
            if (!isNaN(lat) && !isNaN(lng)) takeoffMarker.setLatLng([lat, lng]);
        }
        function syncLandingFromInputs() {
            const lat = parseFloat($landingLatIn.value), lng = parseFloat($landingLngIn.value);
            if (!isNaN(lat) && !isNaN(lng)) {
                ensureLandingMarker(lat, lng);
            } else if (landingMarker) {
                map.removeLayer(landingMarker);
                landingMarker = null;
                if ($btnAddLanding) $btnAddLanding.style.display = '';
            }
        }
        [$latIn, $lngIn].forEach(el => el.addEventListener('change', syncTakeoffFromInputs));
        [$landingLatIn, $landingLngIn].forEach(el => el.addEventListener('change', syncLandingFromInputs));

        // Bouton Recentrer sur le décollage
        $btnRecenter.addEventListener('click', () => {
            map.setView(takeoffMarker.getLatLng(), Math.max(map.getZoom(), 14), { animate: true });
        });

        // Bouton Ajouter atterrissage : place près du décollage
        if ($btnAddLanding) {
            $btnAddLanding.addEventListener('click', () => {
                const ll = takeoffMarker.getLatLng();
                // ~500m au sud du décollage par défaut
                const lat = ll.lat - 0.005, lng = ll.lng;
                $landingLatIn.value = lat.toFixed(6);
                $landingLngIn.value = lng.toFixed(6);
                ensureLandingMarker(lat, lng);
                map.panTo([lat, lng]);
            });
        }

        // Géolocalisation HTML5
        $btnGeo.addEventListener('click', () => {
            if (!navigator.geolocation) {
                alert('Géolocalisation non disponible sur ce navigateur.');
                return;
            }
            $btnGeo.disabled = true;
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const lat = pos.coords.latitude.toFixed(6);
                    const lng = pos.coords.longitude.toFixed(6);
                    $latIn.value = lat;
                    $lngIn.value = lng;
                    takeoffMarker.setLatLng([lat, lng]);
                    map.setView([lat, lng], 15);
                    $btnGeo.disabled = false;
                },
                (err) => {
                    alert('Erreur de géolocalisation : ' + err.message);
                    $btnGeo.disabled = false;
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        });

        // Refresh layout au cas où la carte se chargerait dans un container caché
        setTimeout(() => map.invalidateSize(), 100);
    }
    document.addEventListener('DOMContentLoaded', init);
})();
</script>
@endsection
