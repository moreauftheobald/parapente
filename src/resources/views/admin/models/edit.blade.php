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

        {{-- API source ─────────────────────────────────────────── --}}
        @php
            // Map id => auth_type pour le toggle Alpine.js côté client.
            $apiAuthTypes = $compatibleApis->mapWithKeys(fn ($a) => [(int) $a->id => $a->auth_type])->toArray();
            $oauth2ApiIds = $compatibleApis->where('auth_type', 'oauth2')->pluck('id')->map(fn ($id) => (int) $id)->values()->toArray();
        @endphp
        <div class="bg-gray-900 border border-amber-500/20 rounded-xl p-5 mb-5"
             x-data="{
                 apiId: {{ (int) old('weather_api_id', $model->weather_api_id ?? 0) }},
                 oauth2Ids: {{ json_encode($oauth2ApiIds) }},
                 get isOauth2() { return this.oauth2Ids.includes(Number(this.apiId)); },
                 testing: false,
                 testResult: null,
                 async runTest() {
                     this.testing = true;
                     this.testResult = null;
                     try {
                         const r = await fetch('{{ route('admin.models.test', $model) }}', {
                             method: 'POST',
                             headers: {
                                 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                 'Accept': 'application/json',
                             },
                         });
                         this.testResult = await r.json();
                         this.testResult.httpStatus = r.status;
                     } catch (e) {
                         this.testResult = { success: false, message: 'Erreur réseau: ' + e.message };
                     } finally {
                         this.testing = false;
                     }
                 }
             }">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-amber-500/20">
                <span class="w-8 h-8 rounded-lg bg-amber-500/15 border border-amber-500/30 flex items-center justify-center text-amber-300">
                    <i class="fa-solid fa-plug"></i>
                </span>
                <h2 class="text-sm font-semibold text-amber-200 uppercase tracking-wider">Source API</h2>
            </div>

            <div>
                <label class="{{ $labelCls }}">API utilisée pour le fetch</label>
                <select name="weather_api_id" class="{{ $inputCls }}" x-model.number="apiId">
                    <option value="0">— aucune (modèle non fetché) —</option>
                    @foreach ($compatibleApis as $api)
                        <option value="{{ $api->id }}"
                            @selected(old('weather_api_id', $model->weather_api_id) == $api->id)
                            @if (! $api->active) disabled @endif>
                            {{ $api->name }}
                            @if (! $api->active) (inactive) @endif
                        </option>
                    @endforeach
                </select>
                @error('weather_api_id')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                <p class="text-xs text-gray-500 mt-2">
                    <i class="fa-solid fa-circle-info"></i>
                    Politique single-shot : si le fetch échoue, on log l'erreur et on attend le prochain cycle (pas de fallback).
                    Liste filtrée selon les capacités déclarées par chaque API.
                    @if ($compatibleApis->isEmpty())
                        <span class="text-amber-300">Aucune API ne déclare servir ce modèle.</span>
                    @endif
                </p>
                @if ($model->last_fetch_at)
                    <p class="text-xs text-gray-500 mt-1">
                        Dernier fetch : <span class="font-mono text-gray-400">{{ $model->last_fetch_at->format('Y-m-d H:i') }}</span>
                        ({{ $model->last_fetch_at->diffForHumans() }})
                    </p>
                @endif
            </div>

            {{-- Credentials OAuth2 par modèle (Météo-France) — togglé par Alpine --}}
            <div class="mt-5 pt-5 border-t border-gray-800" x-show="isOauth2" x-cloak>
                <div class="flex items-center gap-2 mb-3">
                    <i class="fa-solid fa-key text-amber-300"></i>
                    <h3 class="text-xs font-semibold text-amber-200 uppercase tracking-wider">Credentials OAuth2 (par modèle)</h3>
                </div>
                <p class="text-xs text-gray-500 mb-3">
                    Le portail Météo-France attribue une paire <code class="font-mono">client_id</code> /
                    <code class="font-mono">client_secret</code> spécifique à chaque souscription d'API. Le token
                    d'accès (1h de durée) est renouvelé automatiquement par le provider.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $labelCls }}">Client ID</label>
                        <input name="oauth_client_id" type="text" autocomplete="off"
                               class="{{ $inputCls }} font-mono text-xs"
                               placeholder="{{ $model->oauth_client_id ? '•••••• (laisser vide pour conserver)' : '' }}">
                    </div>
                    <div>
                        <label class="{{ $labelCls }}">Client secret</label>
                        <input name="oauth_client_secret" type="password" autocomplete="new-password"
                               class="{{ $inputCls }} font-mono text-xs"
                               placeholder="{{ $model->oauth_client_secret ? '•••••• (laisser vide pour conserver)' : '' }}">
                    </div>
                    <div class="md:col-span-2">
                        <label class="{{ $labelCls }}">Endpoint API (URL spécifique de la souscription)</label>
                        <input name="endpoint_url" type="url"
                               class="{{ $inputCls }} font-mono text-xs"
                               value="{{ old('endpoint_url', $model->endpoint_url) }}"
                               placeholder="https://public-api.meteofrance.fr/public/arome/1.0/wcs/...">
                        @error('endpoint_url')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                        <p class="text-[10px] text-gray-600 mt-1">
                            Visible sur le portail dans la fiche de la souscription. Vide = utiliser l'URL par défaut de la classe MeteoFranceApi.
                        </p>
                    </div>
                </div>

                <div class="mt-3 px-3 py-2 rounded bg-gray-950 border border-gray-700 text-[11px] text-gray-400 font-mono">
                    @if ($model->oauth_expires_at)
                        <i class="fa-solid fa-clock"></i> Token actuel expire le {{ $model->oauth_expires_at->format('Y-m-d H:i:s') }}
                        ({{ $model->oauth_expires_at->isPast() ? 'expiré, sera renouvelé' : $model->oauth_expires_at->diffForHumans() }})
                    @else
                        <i class="fa-solid fa-circle-question"></i> Aucun token actif — sera obtenu au premier fetch.
                    @endif
                </div>
            </div>
            {{-- Bouton de test --}}
            <div class="mt-5 pt-5 border-t border-gray-800">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs text-gray-500">
                        <i class="fa-solid fa-flask"></i>
                        Lance un fetch réel sur le 1er site actif pour valider la config.
                        <span class="text-amber-300/80">Utilise les valeurs <strong>sauvegardées</strong> — sauvegarde si tu viens de modifier.</span>
                    </div>
                    <button type="button"
                            @click="runTest()"
                            :disabled="testing || !apiId"
                            class="px-4 py-2 bg-amber-500/15 border border-amber-500/40 hover:bg-amber-500/25 text-amber-200 text-xs font-medium rounded-md transition disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap">
                        <i class="fa-solid" :class="testing ? 'fa-spinner fa-spin' : 'fa-bolt'"></i>
                        <span x-text="testing ? 'Test en cours...' : 'Tester l\'API'"></span>
                    </button>
                </div>

                <div x-show="testResult" x-cloak class="mt-3">
                    <div :class="testResult?.success
                        ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-200'
                        : 'bg-red-500/10 border-red-500/30 text-red-200'"
                         class="px-4 py-3 rounded border text-xs">
                        <div class="flex items-start gap-2">
                            <i class="fa-solid mt-0.5"
                               :class="testResult?.success ? 'fa-circle-check' : 'fa-circle-exclamation'"></i>
                            <div class="flex-1">
                                <div class="font-medium" x-text="testResult?.message"></div>

                                <template x-if="testResult?.last_error">
                                    <pre class="mt-2 text-[11px] font-mono whitespace-pre-wrap text-red-300 opacity-90" x-text="testResult.last_error"></pre>
                                </template>

                                <template x-if="testResult?.success && testResult?.sample">
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-[11px] text-emerald-300 hover:text-emerald-200">
                                            Échantillon de données (3 créneaux)
                                        </summary>
                                        <pre class="mt-2 text-[10px] font-mono text-gray-400 bg-gray-950 p-2 rounded border border-gray-800 overflow-x-auto" x-text="JSON.stringify(testResult.sample, null, 2)"></pre>
                                    </details>
                                </template>
                            </div>
                        </div>
                    </div>
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
