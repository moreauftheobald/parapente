@extends('layouts.admin')
@section('title', 'API · ' . $api->name)

@php
    $inputCls = 'w-full px-2.5 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp

@section('content')
<div>
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

    @if ($errors->any())
        <x-admin.alert type="error">
            <strong>Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </x-admin.alert>
    @endif

    @if ($api->last_error_at && (! $api->last_success_at || $api->last_error_at->gt($api->last_success_at)))
        <x-admin.alert type="error">
            <strong>Dernière erreur ({{ $api->last_error_at->diffForHumans() }})</strong>
            <pre class="mt-1 whitespace-pre-wrap font-mono text-[11px] text-red-200">{{ $api->last_error }}</pre>
        </x-admin.alert>
    @endif

    <form method="POST" action="{{ route('admin.apis.update', $api) }}">
        @csrf
        @method('PATCH')

        {{-- Identité ─────────────────────────────────────────── --}}
        <x-admin.section title="Identité" icon="fa-solid fa-tag" color="sky" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="name" label="Nom" required
                               :value="old('name', $api->name)" hint="Nom d'affichage de l'API." />
                <x-admin.input name="base_url" label="URL de base" type="url" required class="font-mono text-xs"
                               :value="old('base_url', $api->base_url)" hint="URL racine de l'API (ex: http://open-meteo-api:8080/v1)."
                               wrapperClass="xl:col-span-2" />
            </div>

            <div class="mt-3 p-3 rounded-lg
                @class([
                    'bg-emerald-500/10 border border-emerald-500/30' => old('active', $api->active),
                    'bg-gray-950 border border-gray-700' => ! old('active', $api->active),
                ])">
                <input type="hidden" name="active" value="0">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input id="active" name="active" type="checkbox" value="1" @checked(old('active', $api->active))
                           class="w-4 h-4 rounded border-gray-700 bg-gray-950 text-emerald-500 focus:ring-emerald-500/40">
                    <span class="text-sm
                        @class([
                            'text-emerald-300 font-medium' => old('active', $api->active),
                            'text-gray-400' => ! old('active', $api->active),
                        ])">
                        <i class="fa-solid {{ old('active', $api->active) ? 'fa-circle-check' : 'fa-circle-xmark' }}"></i>
                        API {{ old('active', $api->active) ? 'active' : 'inactive' }}
                        <span class="text-xs text-gray-500 ml-1">(les modèles qui pointent dessus seront fetch ou ignorés)</span>
                    </span>
                </label>
            </div>
        </x-admin.section>

        {{-- Authentification ──────────────────────────────────── --}}
        <x-admin.section title="Authentification" icon="fa-solid fa-key" color="amber" class="mb-5">
            @if ($api->auth_type !== 'none')
                <code class="text-[11px] font-mono text-gray-500 mb-3 block">type : {{ $api->auth_type }}</code>
            @endif

            @if ($api->auth_type === 'user_agent')
                <x-admin.input name="user_agent" label="User-Agent" class="font-mono text-xs"
                               :value="old('user_agent', $api->user_agent)"
                               placeholder="QuiVole/1.0 contact@qui-vole.fr"
                               hint="Obligatoire pour MET Norway. Inclure un email de contact." />
            @elseif ($api->auth_type === 'api_key')
                <x-admin.input name="api_key" label="Clé API" type="password" autocomplete="new-password"
                               class="font-mono text-xs"
                               placeholder="{{ $api->api_key ? '•••••• (laisser vide pour conserver)' : 'sk-…' }}"
                               hint="Stockée chiffrée en base." />
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
        </x-admin.section>

        {{-- Quota ─────────────────────────────────────────────── --}}
        <x-admin.section title="Quota" icon="fa-solid fa-gauge-high" color="violet" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="daily_quota" label="Quota déclaré" type="number" step="1" min="0"
                               class="font-mono" placeholder="—"
                               :value="old('daily_quota', $api->daily_quota)"
                               hint="Affichage informatif (req/jour). Vide = illimité ou non documenté." />
                @php
                    $today   = now()->toDateString();
                    $isToday = $api->requests_counter_date && $api->requests_counter_date->toDateString() === $today;
                @endphp
                <x-admin.field label="Compteur du jour" hint="Compteur remis à zéro chaque jour.">
                    <div class="px-2.5 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm font-mono text-gray-300 flex-1">
                        {{ $isToday ? $api->requests_today : 0 }} requête{{ ($isToday && $api->requests_today > 1) ? 's' : '' }}
                    </div>
                </x-admin.field>
            </div>
        </x-admin.section>

        {{-- Modèles servis ────────────────────────────────────── --}}
        <x-admin.section title="Modèles compatibles" icon="fa-solid fa-cloud" color="emerald" class="mb-5">
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
        </x-admin.section>

        <div class="flex items-center justify-between">
            <x-admin.button type="submit" variant="primary" icon="fa-solid fa-floppy-disk">Enregistrer</x-admin.button>
            <a href="{{ route('admin.apis.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>
</div>
@endsection
