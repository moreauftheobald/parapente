@extends('layouts.admin')
@section('title', 'Édition · ' . $model->name)

@php
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $labelCls = 'block text-xs font-medium text-gray-400 mb-1';
    $errorCls = 'text-red-400 text-xs mt-1';
@endphp

@section('content')
<div class="max-w-3xl">
    <div class="bg-gradient-to-r from-sky-500/10 via-gray-900 to-gray-900 border border-sky-500/20 rounded-xl p-5 mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
                <i class="fa-solid fa-cloud text-sky-400"></i> {{ $model->name }}
            </h1>
            <p class="text-xs text-gray-500 mt-1 font-mono">
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">{{ $model->code }}</span>
                <span class="text-gray-600">· {{ $model->provider }}</span>
            </p>
        </div>
        <a href="{{ route('admin.models.index') }}" class="text-sm text-gray-400 hover:text-white transition flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> Liste
        </a>
    </div>

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded bg-red-500/15 border border-red-500/30 text-red-300 text-sm">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.models.update', $model) }}">
        @csrf
        @method('PATCH')

        {{-- Identité ──────────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-sky-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-sky-500/20">
                <span class="w-8 h-8 rounded-lg bg-sky-500/15 border border-sky-500/30 flex items-center justify-center text-sky-300">
                    <i class="fa-solid fa-cloud"></i>
                </span>
                <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider">Identité</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $labelCls }}">Nom <span class="text-red-400">*</span></label>
                    <input name="name" type="text" required class="{{ $inputCls }}" value="{{ old('name', $model->name) }}">
                    @error('name')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Provider <span class="text-red-400">*</span></label>
                    <input name="provider" type="text" required class="{{ $inputCls }}" value="{{ old('provider', $model->provider) }}"
                           placeholder="Météo-France, ECMWF, NOAA…">
                    @error('provider')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2 flex items-center gap-3 p-3 rounded-lg
                    @class([
                        'bg-emerald-500/10 border border-emerald-500/30' => old('active', $model->active),
                        'bg-gray-950 border border-gray-700' => ! old('active', $model->active),
                    ])">
                    <input type="hidden" name="active" value="0">
                    <input id="active" name="active" type="checkbox" value="1" @checked(old('active', $model->active))
                           class="w-4 h-4 rounded border-gray-700 bg-gray-950 text-emerald-500 focus:ring-emerald-500/40">
                    <label for="active" class="text-sm
                        @class([
                            'text-emerald-300 font-medium' => old('active', $model->active),
                            'text-gray-400' => ! old('active', $model->active),
                        ])">
                        <i class="fa-solid {{ old('active', $model->active) ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
                        Modèle {{ old('active', $model->active) ? 'actif' : 'inactif' }}
                        <span class="text-xs text-gray-500 ml-1">(inclus dans la voting logic + fetch)</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Caractéristiques techniques ──────────────────────────── --}}
        <div class="bg-gray-900 border border-violet-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-violet-500/20">
                <span class="w-8 h-8 rounded-lg bg-violet-500/15 border border-violet-500/30 flex items-center justify-center text-violet-300">
                    <i class="fa-solid fa-gauge"></i>
                </span>
                <h2 class="text-sm font-semibold text-violet-200 uppercase tracking-wider">Caractéristiques</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="{{ $labelCls }} flex items-center gap-1">
                        <i class="fa-solid fa-grip text-gray-500"></i> Résolution spatiale
                        <i class="fa-solid fa-lock text-gray-600 text-[10px] ml-auto" title="Défini par le provider"></i>
                    </label>
                    <div class="flex opacity-60">
                        <input type="text" disabled class="{{ $inputCls }} font-mono rounded-r-none cursor-not-allowed"
                               value="{{ number_format((float) $model->resolution_km, 1) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">km</span>
                    </div>
                    <p class="text-[10px] text-gray-600 mt-1">Propriété intrinsèque du modèle source.</p>
                </div>
                <div>
                    <label class="{{ $labelCls }} flex items-center gap-1">
                        <i class="fa-solid fa-arrows-left-right text-gray-500"></i> Horizon max
                        <i class="fa-solid fa-lock text-gray-600 text-[10px] ml-auto" title="Défini par le provider"></i>
                    </label>
                    <div class="flex opacity-60">
                        <input type="text" disabled class="{{ $inputCls }} font-mono rounded-r-none cursor-not-allowed"
                               value="{{ $model->max_horizon_h }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">h</span>
                    </div>
                    <p class="text-[10px] text-gray-600 mt-1">Propriété intrinsèque du modèle source.</p>
                </div>
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-rotate text-violet-400 mr-1"></i> Refresh <span class="text-red-400">*</span></label>
                    <div class="flex">
                        <input name="refresh_frequency_minutes" type="number" step="1" min="5" max="1440" required class="{{ $inputCls }} font-mono rounded-r-none"
                               value="{{ old('refresh_frequency_minutes', $model->refresh_frequency_minutes) }}">
                        <span class="px-2 py-2 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">min</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fa-solid fa-circle-info"></i> Cadence cible de fetch (60 = horaire, 360 = 6h, 720 = 12h).
                    </p>
                </div>
            </div>
        </div>

        {{-- Voting logic ─────────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-emerald-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-emerald-500/20">
                <span class="w-8 h-8 rounded-lg bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-300">
                    <i class="fa-solid fa-scale-balanced"></i>
                </span>
                <h2 class="text-sm font-semibold text-emerald-200 uppercase tracking-wider">Voting logic — poids statiques</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-bolt text-emerald-400 mr-1"></i> Poids court terme (≤ 48h) <span class="text-red-400">*</span></label>
                    <input name="weight_short" type="number" step="0.01" min="0" max="5" required class="{{ $inputCls }} font-mono"
                           value="{{ old('weight_short', $model->weight_short) }}">
                    @error('weight_short')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}"><i class="fa-solid fa-clock text-emerald-400 mr-1"></i> Poids moyen terme (&gt; 48h) <span class="text-red-400">*</span></label>
                    <input name="weight_medium" type="number" step="0.01" min="0" max="5" required class="{{ $inputCls }} font-mono"
                           value="{{ old('weight_medium', $model->weight_medium) }}">
                    @error('weight_medium')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                <i class="fa-solid fa-circle-info"></i>
                Poids relatifs (échelle libre, 0 = ignoré). Phase 3 du système de fiabilité ajoutera une pondération dynamique en complément, par site et balise.
            </p>
        </div>

        <div class="flex items-center justify-between">
            <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-sky-500 to-sky-600 hover:from-sky-400 hover:to-sky-500 text-white text-sm font-medium rounded-md transition shadow-lg shadow-sky-500/20">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer
            </button>
            <a href="{{ route('admin.models.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>
</div>
@endsection
