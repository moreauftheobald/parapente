@extends('layouts.admin')
@section('title', 'API · ' . $api->name)

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
                <i class="fa-solid fa-plug text-sky-400"></i> {{ $api->name }}
            </h1>
            <p class="text-xs text-gray-500 mt-1 font-mono">
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">{{ $api->code }}</span>
                <span class="text-gray-600">· auth: {{ $api->auth_type }}</span>
            </p>
        </div>
        <a href="{{ route('admin.apis.index') }}" class="text-sm text-gray-400 hover:text-white transition flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> Liste
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 px-4 py-3 rounded bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 px-4 py-3 rounded bg-red-500/15 border border-red-500/30 text-red-300 text-sm">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($api->last_error_at && (! $api->last_success_at || $api->last_error_at->gt($api->last_success_at)))
        <div class="mb-4 px-4 py-3 rounded bg-red-500/10 border border-red-500/30 text-red-300 text-xs">
            <strong><i class="fa-solid fa-circle-exclamation"></i> Dernière erreur ({{ $api->last_error_at->diffForHumans() }})</strong>
            <pre class="mt-1 whitespace-pre-wrap font-mono text-[11px] text-red-200">{{ $api->last_error }}</pre>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.apis.update', $api) }}">
        @csrf
        @method('PATCH')

        {{-- Identité ─────────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-sky-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-sky-500/20">
                <span class="w-8 h-8 rounded-lg bg-sky-500/15 border border-sky-500/30 flex items-center justify-center text-sky-300">
                    <i class="fa-solid fa-tag"></i>
                </span>
                <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider">Identité</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $labelCls }}">Nom <span class="text-red-400">*</span></label>
                    <input name="name" type="text" required class="{{ $inputCls }}" value="{{ old('name', $api->name) }}">
                </div>
                <div>
                    <label class="{{ $labelCls }}">URL de base <span class="text-red-400">*</span></label>
                    <input name="base_url" type="url" required class="{{ $inputCls }} font-mono text-xs" value="{{ old('base_url', $api->base_url) }}">
                </div>

                <div class="md:col-span-2 flex items-center gap-3 p-3 rounded-lg
                    @class([
                        'bg-emerald-500/10 border border-emerald-500/30' => old('active', $api->active),
                        'bg-gray-950 border border-gray-700' => ! old('active', $api->active),
                    ])">
                    <input type="hidden" name="active" value="0">
                    <input id="active" name="active" type="checkbox" value="1" @checked(old('active', $api->active))
                           class="w-4 h-4 rounded border-gray-700 bg-gray-950 text-emerald-500 focus:ring-emerald-500/40">
                    <label for="active" class="text-sm
                        @class([
                            'text-emerald-300 font-medium' => old('active', $api->active),
                            'text-gray-400' => ! old('active', $api->active),
                        ])">
                        <i class="fa-solid {{ old('active', $api->active) ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
                        API {{ old('active', $api->active) ? 'active' : 'inactive' }}
                        <span class="text-xs text-gray-500 ml-1">(les modèles qui pointent dessus seront fetch ou ignorés)</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Authentification ──────────────────────────────────── --}}
        <div class="bg-gray-900 border border-amber-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-amber-500/20">
                <span class="w-8 h-8 rounded-lg bg-amber-500/15 border border-amber-500/30 flex items-center justify-center text-amber-300">
                    <i class="fa-solid fa-key"></i>
                </span>
                <h2 class="text-sm font-semibold text-amber-200 uppercase tracking-wider">Authentification</h2>
                <code class="ml-auto text-[11px] font-mono text-gray-500">{{ $api->auth_type }}</code>
            </div>

            @if ($api->auth_type === 'user_agent')
                <div>
                    <label class="{{ $labelCls }}">User-Agent <span class="text-red-400">*</span></label>
                    <input name="user_agent" type="text" class="{{ $inputCls }} font-mono text-xs" value="{{ old('user_agent', $api->user_agent) }}"
                           placeholder="ParapenteFR/1.0 contact@parapentefr.local">
                    <p class="text-xs text-gray-500 mt-1">Obligatoire pour MET Norway. Inclure un email de contact.</p>
                </div>
            @elseif ($api->auth_type === 'api_key')
                <div>
                    <label class="{{ $labelCls }}">Clé API</label>
                    <input name="api_key" type="password" autocomplete="new-password" class="{{ $inputCls }} font-mono text-xs"
                           placeholder="{{ $api->api_key ? '•••••• (laisser vide pour conserver)' : 'sk-…' }}">
                    <p class="text-xs text-gray-500 mt-1">Stockée chiffrée en base.</p>
                </div>
            @elseif ($api->auth_type === 'oauth2')
                <div class="px-3 py-3 rounded bg-violet-500/10 border border-violet-500/30 text-xs text-violet-200">
                    <i class="fa-solid fa-circle-info"></i>
                    Le portail {{ $api->name }} attribue les credentials OAuth2
                    (<code class="font-mono">client_id</code> / <code class="font-mono">client_secret</code>) <strong>par souscription d'API</strong>,
                    donc par modèle. Configure-les dans la page d'édition de chaque modèle servi par cette API
                    (Modèles météo → Éditer un modèle MF → section "Credentials OAuth2").
                </div>
            @else
                <p class="text-xs text-gray-500">Aucune authentification requise.</p>
            @endif
        </div>

        {{-- Quota ─────────────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-violet-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-violet-500/20">
                <span class="w-8 h-8 rounded-lg bg-violet-500/15 border border-violet-500/30 flex items-center justify-center text-violet-300">
                    <i class="fa-solid fa-gauge-high"></i>
                </span>
                <h2 class="text-sm font-semibold text-violet-200 uppercase tracking-wider">Quota</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $labelCls }}">Quota déclaré (req/jour)</label>
                    <input name="daily_quota" type="number" step="1" min="0" class="{{ $inputCls }} font-mono"
                           value="{{ old('daily_quota', $api->daily_quota) }}" placeholder="—">
                    <p class="text-xs text-gray-500 mt-1">Affichage informatif. Vide = illimité ou non documenté.</p>
                </div>
                <div>
                    <label class="{{ $labelCls }}">Compteur du jour</label>
                    @php
                        $today   = now()->toDateString();
                        $isToday = $api->requests_counter_date && $api->requests_counter_date->toDateString() === $today;
                    @endphp
                    <div class="px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm font-mono text-gray-300">
                        {{ $isToday ? $api->requests_today : 0 }} requête{{ ($isToday && $api->requests_today > 1) ? 's' : '' }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Modèles servis ────────────────────────────────────── --}}
        <div class="bg-gray-900 border border-emerald-500/20 rounded-xl p-5 mb-5">
            <div class="flex items-center gap-2 mb-4 pb-3 border-b border-emerald-500/20">
                <span class="w-8 h-8 rounded-lg bg-emerald-500/15 border border-emerald-500/30 flex items-center justify-center text-emerald-300">
                    <i class="fa-solid fa-cloud"></i>
                </span>
                <h2 class="text-sm font-semibold text-emerald-200 uppercase tracking-wider">Modèles compatibles</h2>
            </div>
            @if (count($supportedModelsCodes) > 0)
                <div class="flex flex-wrap gap-2">
                    @foreach ($supportedModelsCodes as $code)
                        <span class="px-2 py-1 rounded bg-gray-800 border border-gray-700 text-xs font-mono text-gray-300">{{ $code }}</span>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-gray-500">Aucun modèle déclaré pour cette API.</p>
            @endif
            <p class="text-xs text-gray-500 mt-3">
                <i class="fa-solid fa-circle-info"></i>
                Cette liste est définie dans la classe d'implémentation
                (<code class="font-mono">supportedModelCodes()</code>). Pour la modifier, éditer le code, pas les données.
            </p>
        </div>

        <div class="flex items-center justify-between">
            <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-sky-500 to-sky-600 hover:from-sky-400 hover:to-sky-500 text-white text-sm font-medium rounded-md transition shadow-lg shadow-sky-500/20">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer
            </button>
            <a href="{{ route('admin.apis.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>
</div>
@endsection
