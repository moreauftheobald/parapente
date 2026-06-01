@extends('layouts.admin')
@section('title', 'Édition · ' . $model->name)

@php
    $inputCls = 'w-full px-2.5 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp

@section('content')
<div>
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
        <x-admin.alert type="error">
            <strong>Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </x-admin.alert>
    @endif

    <form method="POST" action="{{ route('admin.models.update', $model) }}">
        @csrf
        @method('PATCH')

        {{-- Identité ──────────────────────────────────────────── --}}
        <x-admin.section title="Identité" icon="fa-solid fa-cloud" color="sky" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="name" label="Nom" required
                               :value="old('name', $model->name)" hint="Nom d'affichage du modèle." />
                <x-admin.input name="provider" label="Provider" required
                               :value="old('provider', $model->provider)" placeholder="Météo-France, ECMWF, NOAA…"
                               hint="Organisme fournisseur du modèle." />
            </div>

            <div class="mt-3 p-3 rounded-lg
                @class([
                    'bg-emerald-500/10 border border-emerald-500/30' => old('active', $model->active),
                    'bg-gray-950 border border-gray-700' => ! old('active', $model->active),
                ])">
                <input type="hidden" name="active" value="0">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input id="active" name="active" type="checkbox" value="1" @checked(old('active', $model->active))
                           class="w-4 h-4 rounded border-gray-700 bg-gray-950 text-emerald-500 focus:ring-emerald-500/40">
                    <span class="text-sm
                        @class([
                            'text-emerald-300 font-medium' => old('active', $model->active),
                            'text-gray-400' => ! old('active', $model->active),
                        ])">
                        <i class="fa-solid {{ old('active', $model->active) ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
                        Modèle {{ old('active', $model->active) ? 'actif' : 'inactif' }}
                        <span class="text-xs text-gray-500 ml-1">(inclus dans la voting logic + fetch)</span>
                    </span>
                </label>
            </div>
        </x-admin.section>

        {{-- API source ─────────────────────────────────────────── --}}
        @php
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

            <x-admin.field name="weather_api_id" label="API utilisée"
                           hint="Politique single-shot : si le fetch échoue, on log l'erreur et on attend le prochain cycle. Liste filtrée selon les capacités déclarées par chaque API.">
                <select name="weather_api_id" class="{{ $inputCls }} flex-1" x-model.number="apiId">
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
            </x-admin.field>
            @if ($compatibleApis->isEmpty())
                <p class="text-xs text-amber-300 mt-1"><i class="fa-solid fa-triangle-exclamation"></i> Aucune API ne déclare servir ce modèle.</p>
            @endif
            @if ($model->last_fetch_at)
                <p class="text-xs text-gray-500 mt-1">
                    Dernier fetch : <span class="font-mono text-gray-400">{{ $model->last_fetch_at->format('Y-m-d H:i') }}</span>
                    ({{ $model->last_fetch_at->diffForHumans() }})
                </p>
            @endif

            {{-- Credentials OAuth2 par modèle (Météo-France) — togglé par Alpine --}}
            <div class="mt-5 pt-5 border-t border-gray-800" x-show="isOauth2" x-cloak>
                <div class="flex items-center gap-2 mb-3">
                    <i class="fa-solid fa-key text-amber-300"></i>
                    <h3 class="text-xs font-semibold text-amber-200 uppercase tracking-wider">Credentials OAuth2 (par modèle)</h3>
                </div>
                <p class="text-xs text-gray-500 mb-3">
                    Le portail Météo-France attribue une paire <code class="font-mono">client_id</code> /
                    <code class="font-mono">client_secret</code> spécifique à chaque souscription d'API.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                    <x-admin.input name="oauth_client_id" label="Client ID" autocomplete="off"
                                   class="font-mono text-xs"
                                   placeholder="{{ $model->oauth_client_id ? '•••••• (laisser vide pour conserver)' : '' }}"
                                   hint="Identifiant OAuth2 de la souscription." />
                    <x-admin.input name="oauth_client_secret" label="Client secret" type="password"
                                   autocomplete="new-password" class="font-mono text-xs"
                                   placeholder="{{ $model->oauth_client_secret ? '•••••• (laisser vide pour conserver)' : '' }}"
                                   hint="Secret OAuth2 (stocké chiffré)." />
                    <x-admin.input name="endpoint_url" label="Endpoint API" type="url" class="font-mono text-xs"
                                   :value="old('endpoint_url', $model->endpoint_url)"
                                   placeholder="https://public-api.meteofrance.fr/public/arome/1.0/wcs/..."
                                   hint="URL spécifique de la souscription. Vide = utiliser l'URL par défaut de l'API."
                                   wrapperClass="xl:col-span-3" />
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
        <x-admin.section title="Caractéristiques" icon="fa-solid fa-gauge" color="violet" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input label="Résolution spatiale" type="text" disabled suffix="km"
                               class="font-mono opacity-60 cursor-not-allowed"
                               :value="number_format((float) $model->resolution_km, 1)"
                               hint="Propriété intrinsèque du modèle source (non modifiable)." />
                <x-admin.input label="Horizon max" type="text" disabled suffix="h"
                               class="font-mono opacity-60 cursor-not-allowed"
                               :value="$model->max_horizon_h"
                               hint="Propriété intrinsèque du modèle source (non modifiable)." />
                <x-admin.input name="refresh_frequency_minutes" label="Refresh" type="number"
                               step="1" min="5" max="1440" required suffix="min" class="font-mono"
                               :value="old('refresh_frequency_minutes', $model->refresh_frequency_minutes)"
                               hint="Cadence cible de fetch (60 = horaire, 360 = 6h, 720 = 12h)." />
            </div>
        </x-admin.section>

        {{-- Voting logic ─────────────────────────────────────────── --}}
        <x-admin.section title="Voting logic — poids statiques" icon="fa-solid fa-scale-balanced" color="emerald" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="weight_short" label="Poids court terme (≤ 48h)" type="number"
                               step="0.01" min="0" max="5" required class="font-mono"
                               :value="old('weight_short', $model->weight_short)"
                               hint="Poids relatif (échelle libre, 0 = ignoré). Pondération dynamique prévue en phase 3." />
                <x-admin.input name="weight_medium" label="Poids moyen terme (> 48h)" type="number"
                               step="0.01" min="0" max="5" required class="font-mono"
                               :value="old('weight_medium', $model->weight_medium)"
                               hint="Poids relatif (échelle libre, 0 = ignoré). Pondération dynamique prévue en phase 3." />
            </div>
        </x-admin.section>

        {{-- Fiabilité (sidecar consensus) ────────────────────────── --}}
        <x-admin.section title="Fiabilité — pondération sidecar" icon="fa-solid fa-flask-vial" color="amber" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="weight_factor" label="Weight factor global" type="number"
                               step="0.01" min="0" max="5" required class="font-mono"
                               :value="old('weight_factor', $model->weight_factor)"
                               hint="Facteur de fiabilité global lu par le sidecar (0 = ignoré dans le consensus, 1.0 = neutre, > 1.0 = boosté). La table model_reliability fournit des poids fins par (variable, horizon, balise)." />
            </div>
            @if (old('weight_factor', $model->weight_factor) < 0.1 && old('weight_factor', $model->weight_factor) != 0)
                <x-admin.alert type="warning" class="mt-3">Valeur très basse — le modèle aura un poids quasi-nul dans le consensus.</x-admin.alert>
            @endif
            @if (old('weight_factor', $model->weight_factor) > 2.0)
                <x-admin.alert type="warning" class="mt-3">Valeur élevée — ce modèle dominera le consensus. Vérifiez que c'est intentionnel.</x-admin.alert>
            @endif

            <div class="mt-3">
                <x-admin.field name="notes_admin" label="Notes admin" :inline="false"
                               hint="Champ libre : raison d'un poids modifié, observations, remarques opérationnelles.">
                    <textarea name="notes_admin" id="notes_admin" rows="2"
                              class="{{ $inputCls }}">{{ old('notes_admin', $model->notes_admin) }}</textarea>
                </x-admin.field>
            </div>
        </x-admin.section>

        <div class="flex items-center justify-between">
            <x-admin.button type="submit" icon="fa-solid fa-floppy-disk">Enregistrer</x-admin.button>
            <a href="{{ route('admin.models.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>
</div>
@endsection
