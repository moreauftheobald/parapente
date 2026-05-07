@extends('layouts.admin')
@section('title', 'Édition · ' . $site->name)

@php
    $cond = $site->conditions;
    /** Helpers d'input réutilisables */
    $inputCls   = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500';
    $labelCls   = 'block text-xs font-medium text-gray-400 mb-1';
    $errorCls   = 'text-red-400 text-xs mt-1';
    $sectionCls = 'bg-gray-900 border border-gray-800 rounded-xl p-5 mb-5';
@endphp

@section('content')
<div class="max-w-4xl">
    <div class="flex items-baseline justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-white">{{ $site->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                <span class="font-mono">{{ $site->source }}</span>
                @if ($site->external_id)
                    · <span class="font-mono">#{{ $site->external_id }}</span>
                @endif
                · créé le {{ $site->created_at->format('d/m/Y') }}
            </p>
        </div>
        <a href="{{ route('admin.sites.index', request()->only(['source','active','level','region','search','sort','dir','page'])) }}"
           class="text-sm text-gray-400 hover:text-white transition">↩ Retour à la liste</a>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded bg-red-500/15 border border-red-500/30 text-red-300 text-sm">
            <strong>Quelques erreurs :</strong>
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

        {{-- Site ────────────────────────────────────────────────── --}}
        <div class="{{ $sectionCls }}">
            <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-4">Site</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $labelCls }}">Nom *</label>
                    <input name="name" type="text" required class="{{ $inputCls }}" value="{{ old('name', $site->name) }}">
                    @error('name')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Slug *</label>
                    <input name="slug" type="text" required class="{{ $inputCls }}" value="{{ old('slug', $site->slug) }}">
                    @error('slug')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="{{ $labelCls }}">Région</label>
                    <input name="region" type="text" class="{{ $inputCls }}" value="{{ old('region', $site->region) }}" placeholder="ex: grand-est">
                    @error('region')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Niveau requis *</label>
                    <select name="level" required class="{{ $inputCls }}">
                        @foreach (['debutant'=>'Débutant','intermediaire'=>'Intermédiaire','confirme'=>'Confirmé'] as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('level', $site->level) === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="{{ $labelCls }}">Description</label>
                    <textarea name="description" rows="4" class="{{ $inputCls }}">{{ old('description', $site->description) }}</textarea>
                </div>

                <div class="md:col-span-2 flex items-center gap-3 pt-2">
                    <input type="hidden" name="active" value="0">
                    <input id="active" name="active" type="checkbox" value="1" @checked(old('active', $site->active))
                           class="w-4 h-4 rounded border-gray-700 bg-gray-950 text-sky-500 focus:ring-sky-500/40">
                    <label for="active" class="text-sm text-gray-300">
                        Actif (inclus dans le fetch météo + visible sur la carte publique)
                    </label>
                </div>
            </div>
        </div>

        {{-- Coordonnées ─────────────────────────────────────────── --}}
        <div class="{{ $sectionCls }}">
            <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-4">Coordonnées</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="{{ $labelCls }}">Latitude (décollage) *</label>
                    <input name="latitude" type="number" step="0.000001" required class="{{ $inputCls }} font-mono"
                           value="{{ old('latitude', $site->latitude) }}">
                    @error('latitude')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Longitude (décollage) *</label>
                    <input name="longitude" type="number" step="0.000001" required class="{{ $inputCls }} font-mono"
                           value="{{ old('longitude', $site->longitude) }}">
                    @error('longitude')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Altitude (m)</label>
                    <input name="altitude_m" type="number" step="1" min="0" max="9000" class="{{ $inputCls }} font-mono"
                           value="{{ old('altitude_m', $site->altitude_m) }}">
                    @error('altitude_m')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="{{ $labelCls }}">Latitude atterrissage</label>
                    <input name="landing_lat" type="number" step="0.000001" class="{{ $inputCls }} font-mono"
                           value="{{ old('landing_lat', $site->landing_lat) }}">
                    @error('landing_lat')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Longitude atterrissage</label>
                    <input name="landing_lng" type="number" step="0.000001" class="{{ $inputCls }} font-mono"
                           value="{{ old('landing_lng', $site->landing_lng) }}">
                    @error('landing_lng')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-end">
                    <a href="https://www.google.com/maps/place/{{ $site->latitude }},{{ $site->longitude }}"
                       target="_blank" rel="noopener"
                       class="text-xs text-sky-400 hover:text-sky-300 transition">↗ Voir sur Google Maps</a>
                </div>
            </div>
        </div>

        {{-- Conditions de vol ───────────────────────────────────── --}}
        <div class="{{ $sectionCls }}">
            <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-4">Conditions de vol favorables</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="{{ $labelCls }}">Direction min (°) *</label>
                    <input name="wind_dir_min" type="number" min="0" max="360" required class="{{ $inputCls }} font-mono"
                           value="{{ old('wind_dir_min', $cond?->wind_dir_min ?? 0) }}">
                    @error('wind_dir_min')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Direction max (°) *</label>
                    <input name="wind_dir_max" type="number" min="0" max="360" required class="{{ $inputCls }} font-mono"
                           value="{{ old('wind_dir_max', $cond?->wind_dir_max ?? 360) }}">
                    @error('wind_dir_max')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                    <p class="text-xs text-gray-500 mt-1">Convention FROM (météo). Si min &gt; max, la plage chevauche le Nord (ex: 340 → 30).</p>
                </div>

                <div>
                    <label class="{{ $labelCls }}">Vitesse min (km/h) *</label>
                    <input name="wind_speed_min" type="number" step="0.1" min="0" max="100" required class="{{ $inputCls }} font-mono"
                           value="{{ old('wind_speed_min', $cond?->wind_speed_min ?? 0) }}">
                </div>
                <div>
                    <label class="{{ $labelCls }}">Vitesse max (km/h) *</label>
                    <input name="wind_speed_max" type="number" step="0.1" min="0" max="100" required class="{{ $inputCls }} font-mono"
                           value="{{ old('wind_speed_max', $cond?->wind_speed_max ?? 25) }}">
                </div>
                <div>
                    <label class="{{ $labelCls }}">Vitesse idéale (km/h) *</label>
                    <input name="wind_speed_ideal" type="number" step="0.1" min="0" max="100" required class="{{ $inputCls }} font-mono"
                           value="{{ old('wind_speed_ideal', $cond?->wind_speed_ideal ?? 12) }}">
                </div>
                <div>
                    <label class="{{ $labelCls }}">Précipitations max (mm/h) *</label>
                    <input name="precip_max" type="number" step="0.1" min="0" max="50" required class="{{ $inputCls }} font-mono"
                           value="{{ old('precip_max', $cond?->precip_max ?? 0) }}">
                </div>

                <div>
                    <label class="{{ $labelCls }}">Plafond nuageux min (m) *</label>
                    <input name="cloud_base_min_m" type="number" min="0" max="5000" required class="{{ $inputCls }} font-mono"
                           value="{{ old('cloud_base_min_m', $cond?->cloud_base_min_m ?? 800) }}">
                </div>
                <div>
                    <label class="{{ $labelCls }}">Couverture nuageuse basse max (%) *</label>
                    <input name="cloud_cover_low_max" type="number" min="0" max="100" required class="{{ $inputCls }} font-mono"
                           value="{{ old('cloud_cover_low_max', $cond?->cloud_cover_low_max ?? 50) }}">
                </div>

                <div class="md:col-span-2">
                    <label class="{{ $labelCls }}">Notes</label>
                    <textarea name="notes" rows="3" class="{{ $inputCls }}">{{ old('notes', $cond?->notes) }}</textarea>
                </div>
            </div>
        </div>

        {{-- Actions ─────────────────────────────────────────────── --}}
        <div class="flex items-center justify-between mt-6">
            <button type="submit" class="px-5 py-2 bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium rounded-md transition">
                Enregistrer
            </button>

            <a href="{{ route('admin.sites.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>

    {{-- Suppression en zone séparée pour éviter les clics accidentels --}}
    <div class="mt-12 pt-6 border-t border-red-500/20">
        <h3 class="text-sm font-semibold text-red-300 mb-2">Zone dangereuse</h3>
        <p class="text-xs text-gray-500 mb-3">
            La suppression efface le site et toutes ses données associées (conditions, forecasts, scores). Action irréversible.
        </p>
        <form method="POST" action="{{ route('admin.sites.destroy', $site) }}"
              onsubmit="return confirm('Supprimer définitivement « {{ addslashes($site->name) }} » ? Toutes ses données associées seront effacées.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="px-4 py-2 bg-red-500/15 border border-red-500/40 text-red-300 hover:bg-red-500/25 text-sm rounded-md transition">
                Supprimer ce site
            </button>
        </form>
    </div>
</div>
@endsection
