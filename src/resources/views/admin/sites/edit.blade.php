@extends('layouts.admin')
@section('title', 'Édition · ' . $site->name)

@php
    $cond = $site->conditions;
    $inputCls = 'w-full px-2.5 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp

@section('content')
<div>
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
        <x-admin.alert type="error">
            <strong>Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </x-admin.alert>
    @endif

    <form method="POST" action="{{ route('admin.sites.update', $site) }}">
        @csrf
        @method('PATCH')

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
            {{-- Section Site ──────────────────────────────────────── --}}
            <x-admin.section title="Identité" icon="fa-solid fa-mountain-sun" color="sky">
                <div class="space-y-3">
                    <x-admin.input name="name" label="Nom" required
                                   :value="old('name', $site->name)" hint="Nom du site de vol." />
                    <x-admin.input name="region" label="Région"
                                   :value="old('region', $site->region)" placeholder="ex: grand-est"
                                   hint="Région technique (slug). Distinction avec admin_region (géocodage)." />
                    <x-admin.field name="level" label="Niveau requis"
                                   hint="Niveau pilote minimum recommandé pour ce site.">
                        <select name="level" required class="{{ $inputCls }} flex-1">
                            @foreach (['debutant'=>'Débutant','intermediaire'=>'Intermédiaire','confirme'=>'Confirmé'] as $val => $lbl)
                                <option value="{{ $val }}" @selected(old('level', $site->level) === $val)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field name="description" label="Description" :inline="false"
                                   hint="Description libre du site (conditions habituelles, accès, etc.).">
                        <textarea name="description" rows="3" class="{{ $inputCls }}">{{ old('description', $site->description) }}</textarea>
                    </x-admin.field>

                    <div class="p-3 rounded-lg
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
            </x-admin.section>

            {{-- Section Coordonnées ───────────────────────────────── --}}
            <x-admin.section title="Coordonnées" icon="fa-solid fa-location-dot" color="violet">
                <div class="space-y-3 mb-3">
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.input id="input-lat" name="latitude" label="Lat. déco" type="number"
                                       step="0.000001" required class="font-mono"
                                       :value="old('latitude', $site->latitude)"
                                       hint="Latitude du décollage (WGS84)." />
                        <x-admin.input id="input-lng" name="longitude" label="Lng. déco" type="number"
                                       step="0.000001" required class="font-mono"
                                       :value="old('longitude', $site->longitude)"
                                       hint="Longitude du décollage (WGS84)." />
                    </div>
                    <x-admin.input name="altitude_m" label="Altitude" type="number" step="1" min="0" max="9000"
                                   suffix="m" class="font-mono"
                                   :value="old('altitude_m', $site->altitude_m)"
                                   hint="Altitude du décollage (mètres ASL)." />
                    <div class="grid grid-cols-2 gap-3">
                        <x-admin.input id="input-landing-lat" name="landing_lat" label="Lat. atterro" type="number"
                                       step="0.000001" class="font-mono"
                                       :value="old('landing_lat', $site->landing_lat)"
                                       hint="Latitude de l'atterrissage (optionnel)." />
                        <x-admin.input id="input-landing-lng" name="landing_lng" label="Lng. atterro" type="number"
                                       step="0.000001" class="font-mono"
                                       :value="old('landing_lng', $site->landing_lng)"
                                       hint="Longitude de l'atterrissage (optionnel)." />
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
            </x-admin.section>
        </div>

        {{-- Section Conditions de vol ───────────────────────────── --}}
        <x-admin.section title="Conditions de vol favorables" icon="fa-solid fa-wind" color="emerald" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="wind_dir_min" label="Direction min" type="number" min="0" max="360"
                               required suffix="°" class="font-mono"
                               :value="old('wind_dir_min', $cond?->wind_dir_min ?? 0)"
                               hint="Direction FROM minimale (convention météo). Si min > max, la plage chevauche le Nord." />
                <x-admin.input name="wind_dir_max" label="Direction max" type="number" min="0" max="360"
                               required suffix="°" class="font-mono"
                               :value="old('wind_dir_max', $cond?->wind_dir_max ?? 360)"
                               hint="Direction FROM maximale (convention météo)." />

                <x-admin.input name="wind_speed_min" label="Vitesse min" type="number" step="0.1" min="0" max="100"
                               required suffix="km/h" class="font-mono"
                               :value="old('wind_speed_min', $cond?->wind_speed_min ?? 0)"
                               hint="Vitesse de vent moyen minimale acceptable." />
                <x-admin.input name="wind_speed_max" label="Vitesse max" type="number" step="0.1" min="0" max="100"
                               required suffix="km/h" class="font-mono"
                               :value="old('wind_speed_max', $cond?->wind_speed_max ?? 25)"
                               hint="Vitesse de vent moyen maximale acceptable." />
                <x-admin.input name="wind_speed_ideal" label="Vitesse idéale" type="number" step="0.1" min="0" max="100"
                               required suffix="km/h" class="font-mono"
                               :value="old('wind_speed_ideal', $cond?->wind_speed_ideal ?? 12)"
                               hint="Vitesse optimale pour le site." />

                <x-admin.input name="cloud_base_min_m" label="Plafond min" type="number" min="0" max="5000"
                               required suffix="m" class="font-mono"
                               :value="old('cloud_base_min_m', $cond?->cloud_base_min_m ?? 800)"
                               hint="Base des nuages minimum (m ASL, règle d'Espy). Orange dans une marge de 100 m, rouge en dessous." />
                <x-admin.input name="cloud_cover_low_max" label="Couv. nuages basse max" type="number" min="0" max="100"
                               required suffix="%" class="font-mono"
                               :value="old('cloud_cover_low_max', $cond?->cloud_cover_low_max ?? 50)"
                               hint="Couverture nuageuse basse maximale tolérée." />

                <x-admin.input name="wind_gust_orange_kmh" label="Rafale orange (override)" type="number"
                               step="0.1" min="0" max="200" suffix="km/h" class="font-mono"
                               placeholder="globale"
                               :value="old('wind_gust_orange_kmh', $cond?->wind_gust_orange_kmh)"
                               hint="Surcharge du seuil orange de rafales pour ce site. Vide = valeur globale." />
                <x-admin.input name="wind_gust_red_kmh" label="Rafale rouge (override)" type="number"
                               step="0.1" min="0" max="200" suffix="km/h" class="font-mono"
                               placeholder="globale"
                               :value="old('wind_gust_red_kmh', $cond?->wind_gust_red_kmh)"
                               hint="Surcharge du seuil rouge de rafales pour ce site. Vide = valeur globale." />
            </div>
            <p class="text-xs text-gray-500 mt-3">
                <i class="fa-solid fa-circle-info"></i> Les seuils de pluie sont globaux ; les seuils de rafales aussi
                sauf surcharge ci-dessus (laisser vide pour utiliser la valeur globale).
                <a href="{{ route('admin.settings.index') }}" class="text-sky-400 hover:text-sky-300">Paramètres généraux</a>
            </p>

            <div class="mt-3">
                <x-admin.field name="notes" label="Notes" :inline="false"
                               hint="Notes libres sur les conditions de vol du site.">
                    <textarea name="notes" rows="2" class="{{ $inputCls }}">{{ old('notes', $cond?->notes) }}</textarea>
                </x-admin.field>
            </div>
        </x-admin.section>

        {{-- Actions ─────────────────────────────────────────────── --}}
        <div class="flex items-center justify-between mb-8">
            <x-admin.button type="submit" icon="fa-solid fa-floppy-disk">Enregistrer</x-admin.button>
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
                <x-admin.button type="submit" variant="danger" icon="fa-solid fa-trash">Supprimer</x-admin.button>
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
