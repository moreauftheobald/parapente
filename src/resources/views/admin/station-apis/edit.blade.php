@extends('layouts.admin')
@section('title', 'API · ' . $api->networkLabel())

@section('content')
<div>
    <div class="bg-gradient-to-r from-sky-500/10 via-gray-900 to-gray-900 border border-sky-500/20 rounded-xl p-5 mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
                <i class="fa-solid fa-tower-broadcast" style="color:{{ $api->networkColor() }}"></i>
                {{ $api->networkLabel() }}
            </h1>
            <p class="text-xs text-gray-500 mt-1 font-mono">
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">{{ $api->code }}</span>
                <span class="text-gray-600">· auth: {{ $api->auth_type }}</span>
            </p>
        </div>
        <a href="{{ route('admin.station-apis.index') }}" class="text-sm text-gray-400 hover:text-white transition flex items-center gap-2">
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

    <form method="POST" action="{{ route('admin.station-apis.update', $api) }}">
        @csrf
        @method('PATCH')

        {{-- Identité ─────────────────────────────────────────── --}}
        <x-admin.section title="Identité" icon="fa-solid fa-tag" color="sky" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="name" label="Nom" required
                               :value="old('name', $api->name)" hint="Nom d'affichage de l'API." />
                <x-admin.input name="base_url" label="URL de base" type="url"
                               class="font-mono text-xs"
                               :value="old('base_url', $api->base_url)"
                               hint="URL racine de l'API."
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
                        <span class="text-xs text-gray-500 ml-1">(les stations de ce réseau seront fetch ou ignorées)</span>
                    </span>
                </label>
            </div>
        </x-admin.section>

        {{-- Authentification ──────────────────────────────────── --}}
        <x-admin.section title="Authentification" icon="fa-solid fa-key" color="amber" class="mb-5">
            @if ($api->auth_type !== 'none')
                <code class="text-[11px] font-mono text-gray-500 mb-3 block">type : {{ $api->auth_type }}</code>
            @endif

            @if ($api->auth_type === 'oauth2')
                <div class="space-y-4">
                    <x-admin.input name="api_key" label="Credentials Basic (Base64)" type="password" autocomplete="new-password"
                                   class="font-mono text-xs"
                                   placeholder="{{ $api->api_key ? '•••••• (laisser vide pour conserver)' : 'ZTZnR010QmhxODl…' }}"
                                   hint="Base64 de client_id:client_secret — la valeur après « Basic » dans le curl. Stockée chiffrée en base." />

                    <div class="p-3 rounded-lg bg-violet-500/10 border border-violet-500/30">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="fa-solid fa-circle-info text-violet-300"></i>
                            <span class="text-sm text-violet-200 font-medium">OAuth2 Client Credentials</span>
                        </div>
                        <div class="text-xs text-violet-200/80 space-y-1">
                            <p><strong>Token URL :</strong> <code class="font-mono text-violet-300">{{ $api->config['token_url'] ?? $api->base_url . '/token' }}</code></p>
                            <p><strong>Grant type :</strong> <code class="font-mono text-violet-300">client_credentials</code></p>
                            <p>Le token est obtenu automatiquement et caché en Redis jusqu'à expiration (marge de 60 s).</p>
                        </div>
                        @if ($api->hasOAuth2Credentials())
                            <div class="mt-2 flex items-center gap-2">
                                @if ($api->isOAuth2TokenCached())
                                    <span class="px-2 py-0.5 text-[10px] rounded border border-emerald-500/30 bg-emerald-500/10 text-emerald-300">
                                        <i class="fa-solid fa-circle-check"></i> Token en cache
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 text-[10px] rounded border border-gray-600 bg-gray-800 text-gray-400">
                                        <i class="fa-solid fa-clock"></i> Token expiré — sera renouvelé au prochain appel
                                    </span>
                                @endif
                            </div>
                        @else
                            <div class="mt-2">
                                <span class="px-2 py-0.5 text-[10px] rounded border border-amber-500/30 bg-amber-500/10 text-amber-300">
                                    <i class="fa-solid fa-key"></i> Credentials non configurés
                                </span>
                            </div>
                        @endif
                    </div>
                </div>
            @elseif ($api->auth_type === 'api_key')
                <x-admin.input name="api_key" label="Clé API" type="password" autocomplete="new-password"
                               class="font-mono text-xs"
                               placeholder="{{ $api->api_key ? '•••••• (laisser vide pour conserver)' : 'Saisir la clé API…' }}"
                               hint="Stockée chiffrée en base." />
            @elseif ($api->auth_type === 'user_agent')
                <x-admin.input name="user_agent" label="User-Agent" class="font-mono text-xs"
                               :value="old('user_agent', $api->user_agent)"
                               placeholder="QuiVole/1.0 contact@qui-vole.fr"
                               hint="Identifiant envoyé avec chaque requête." />
            @else
                <p class="text-xs text-gray-500">Aucune authentification requise pour ce réseau.</p>
            @endif
        </x-admin.section>

        {{-- Quota ─────────────────────────────────────────────── --}}
        <x-admin.section title="Quota" icon="fa-solid fa-gauge-high" color="violet" class="mb-5">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-3">
                <x-admin.input name="daily_quota" label="Quota déclaré" type="number" step="1" min="0"
                               class="font-mono" placeholder="—"
                               :value="old('daily_quota', $api->daily_quota)"
                               hint="Affichage informatif (req/jour). Vide = illimité." />
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

        {{-- Configuration réseau ──────────────────────────────── --}}
        <x-admin.section title="Configuration réseau" icon="fa-solid fa-sliders" color="emerald" class="mb-5">
            @if ($api->config && count($api->config) > 0)
                <div class="space-y-2">
                    @foreach ($api->config as $key => $value)
                        <div class="flex items-center gap-3 text-xs">
                            <span class="font-mono text-gray-400 min-w-[160px]">{{ $key }}</span>
                            <span class="font-mono text-gray-300">{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-gray-500">Aucune configuration spécifique pour ce réseau.</p>
            @endif
            <p class="text-xs text-gray-500 mt-3">
                <i class="fa-solid fa-circle-info"></i>
                La configuration réseau est définie dans le code (seeder). Pour la modifier, éditer le seeder et re-seeder.
            </p>
        </x-admin.section>

        <div class="flex items-center justify-between">
            <x-admin.button type="submit" variant="primary" icon="fa-solid fa-floppy-disk">Enregistrer</x-admin.button>
            <a href="{{ route('admin.station-apis.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>
</div>
@endsection
