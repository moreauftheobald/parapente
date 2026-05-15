@extends('layouts.admin')
@section('title', 'Paramètres généraux')

@section('help')
    <div class="text-sm text-gray-400 leading-relaxed space-y-3">
        <p>
            Cette page regroupe les <strong>seuils globaux de scoring</strong> appliqués à
            tous les sites.
        </p>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Précipitations</div>
            Seuil orange et seuil rouge sur le <em>consensus</em> multi-modèles
            de précipitations (mm/h). Une heure dont le consensus dépasse le seuil
            orange passe en orange ; au-delà du seuil rouge, elle est éliminée.
        </div>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Rafales</div>
            Seuils globaux par défaut. Chaque site peut surcharger ces valeurs
            depuis la fiche site (champs « Rafale orange / rouge »).
        </div>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Viabilité d'une journée</div>
            Réglages de la cloche horaire (créneaux du milieu de journée &gt; tôt
            le matin / fin de journée), de la continuité (un créneau isolé pèse
            moins), et des seuils de qualification d'une journée (vert / orange / rouge).
        </div>
        <div>
            <div class="font-semibold text-gray-200 mb-1">Sources balises</div>
            Clés API des fournisseurs externes (Windy…). Stockées chiffrées dans
            la table <code>settings</code>. Effacer le champ supprime la clé.
        </div>
        <p class="text-xs text-gray-500 mt-3 pt-3 border-t border-gray-800">
            Les valeurs prennent effet <strong>immédiatement</strong> pour les nouveaux
            scores. Pour appliquer les nouvelles couleurs aux scores déjà en base
            sans attendre le prochain fetch horaire :
            <code class="text-sky-400">php artisan scores:recompute-detail-colors</code>.
        </p>
    </div>
@endsection

@section('content')
@php
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 font-mono focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $labelCls = 'block text-xs font-medium text-gray-300 mb-1';
    $errorCls = 'text-red-400 text-xs mt-1';
    $field = fn (string $key) => str_replace('.', '__', $key);
@endphp

<div class="max-w-4xl">
    <h1 class="text-2xl font-semibold text-white">Paramètres généraux</h1>
    <p class="text-sm text-gray-500 mt-1 mb-6">
        Seuils globaux du scoring : précipitations, rafales, viabilité du jour.
    </p>

    @if (session('status'))
        <div class="mb-4 px-4 py-3 rounded bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-sm">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif

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

    <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
        @csrf
        @method('PATCH')

        @foreach ($groups as $groupKey => $group)
            @if (! empty($group['keys']))
                <section class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                    <h2 class="text-sm font-semibold text-sky-200 uppercase tracking-wider mb-4 flex items-center gap-2">
                        <i class="fa-solid {{ $group['icon'] ?? 'fa-gear' }}"></i> {{ $group['title'] }}
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                        @foreach ($group['keys'] as $key => $meta)
                            @php
                                $name = $field($key);
                                $type = $meta['type'] ?? 'float';
                                $isSecret = $type === 'secret';
                                $isString = $type === 'string';
                                $colSpan = ($isSecret || $isString) ? 'md:col-span-2' : '';
                            @endphp
                            <div class="{{ $colSpan }}">
                                <label class="{{ $labelCls }}" for="{{ $name }}">
                                    {{ $meta['label'] ?? $key }}
                                </label>
                                @if ($isSecret)
                                    @php
                                        $current = (string) ($meta['value'] ?? '');
                                        $hasValue = $current !== '';
                                        $tail = $hasValue ? substr($current, -4) : '';
                                    @endphp
                                    <div x-data="{ shown: false }" class="relative">
                                        <input :type="shown ? 'text' : 'password'"
                                               id="{{ $name }}"
                                               name="{{ $name }}"
                                               autocomplete="new-password"
                                               value="{{ old($name, $current) }}"
                                               placeholder="{{ $hasValue ? '••••••••••••' . $tail : 'Coller la clé ici…' }}"
                                               class="{{ $inputCls }} pr-10">
                                        <button type="button"
                                                @click="shown = ! shown"
                                                tabindex="-1"
                                                class="absolute inset-y-0 right-2 flex items-center px-2 text-gray-500 hover:text-gray-200 transition"
                                                :title="shown ? 'Masquer' : 'Afficher'">
                                            <i :class="shown ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'" class="text-xs"></i>
                                        </button>
                                    </div>
                                    @if ($hasValue)
                                        <p class="text-[11px] text-emerald-400 mt-1">
                                            <i class="fa-solid fa-circle-check"></i> Clé enregistrée (se termine par <code>{{ $tail }}</code>). Laisser vide pour la supprimer.
                                        </p>
                                    @endif
                                @elseif ($isString)
                                    <input type="text"
                                           id="{{ $name }}"
                                           name="{{ $name }}"
                                           value="{{ old($name, $meta['value']) }}"
                                           class="{{ $inputCls }}">
                                @else
                                    <input type="number"
                                           id="{{ $name }}"
                                           name="{{ $name }}"
                                           step="{{ $type === 'int' ? '1' : 'any' }}"
                                           min="0"
                                           required
                                           value="{{ old($name, $meta['value']) }}"
                                           class="{{ $inputCls }}">
                                @endif
                                @if (! empty($meta['description']))
                                    <p class="text-xs text-gray-500 mt-1 leading-relaxed">{{ $meta['description'] }}</p>
                                @endif
                                <p class="text-[11px] text-gray-600 mt-1">
                                    <span class="text-gray-700">clé :</span> <code>{{ $key }}</code>
                                    @if (! $isSecret)
                                        <span class="text-gray-700">· défaut :</span> <code>{{ $meta['default'] }}</code>
                                    @endif
                                </p>
                                @error($name) <p class="{{ $errorCls }}">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach

        <div class="flex items-center justify-between">
            <button type="submit"
                    class="px-4 py-2 bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium rounded-md transition">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer tous les paramètres
            </button>
            <a href="{{ route('admin.dashboard') }}" class="text-sm text-gray-400 hover:text-white transition">
                Retour au dashboard
            </a>
        </div>
    </form>
</div>
@endsection
