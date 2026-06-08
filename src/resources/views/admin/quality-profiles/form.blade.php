@extends('layouts.admin')
@section('title', $profile ? 'Modifier profil qualité' : 'Nouveau profil qualité')

@php
    $isEdit    = $profile !== null;
    $axesByKey = $isEdit ? $profile->axes->keyBy('axis') : collect();
@endphp

@section('content')
<div class="max-w-4xl">
    <x-admin.page-title :title="$isEdit ? 'Modifier le profil' : 'Nouveau profil qualité'">
        <x-slot:subtitle>{{ $isEdit ? $profile->label : 'Configurez le profil, ses poids par axe et les courbes de scoring.' }}</x-slot:subtitle>
    </x-admin.page-title>

    <form method="POST"
          action="{{ $isEdit ? route('admin.quality-profiles.update', $profile) : route('admin.quality-profiles.store') }}"
          x-data="profileForm()"
          x-init="init()">
        @csrf
        @if ($isEdit) @method('PATCH') @endif

        {{-- ── Infos générales ──────────────────────────────────────── --}}
        <x-admin.section title="Informations générales" icon="fa-solid fa-tag" color="sky" class="mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-admin.input name="label" label="Nom du profil" type="text"
                               :value="old('label', $profile?->label ?? '')"
                               hint="Ex : Piou-Piou (découverte), Local (soaring), Cross-country" />
                <x-admin.input name="slug" label="Slug technique" type="text"
                               :value="old('slug', $profile?->slug ?? '')"
                               hint="Identifiant unique. Laissez vide pour auto-générer." />
                <x-admin.input name="sort_order" label="Ordre d'affichage" type="number"
                               :value="old('sort_order', $profile?->sort_order ?? 0)"
                               min="0" />
                <div class="flex items-center gap-3 self-end pb-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="hidden" name="active" value="0">
                        <input type="checkbox" name="active" value="1"
                               class="rounded bg-gray-950 border-gray-700 text-sky-500 focus:ring-sky-500/40"
                               @checked(old('active', $profile?->active ?? true))>
                        <span class="text-sm text-gray-300">Actif</span>
                    </label>
                    <span class="text-xs text-gray-500">(lu par le sidecar au prochain run)</span>
                </div>
            </div>
        </x-admin.section>

        {{-- ── Axes et courbes ──────────────────────────────────────── --}}
        <x-admin.section title="Axes et courbes de scoring" icon="fa-solid fa-chart-line" color="emerald" class="mb-6">
            <p class="text-xs text-gray-500 mb-4">
                Définissez le poids (0-100, somme = 100) et la courbe de scoring pour chaque axe.
                La courbe est une liste de points <code class="text-sky-400">{value, score}</code> ordonnés par valeur croissante.
                Le sidecar interpole linéairement entre les points.
            </p>

            <div class="space-y-4">
                @foreach ($availableAxes as $axisKey => $meta)
                    @php
                        $existing = $axesByKey->get($axisKey);
                        $weight   = old("axes.{$axisKey}.weight", $existing?->weight ?? 0);
                        $curve    = old("axes.{$axisKey}.scoring_curve",
                            $existing ? json_encode($existing->scoring_curve, JSON_PRETTY_PRINT) : ''
                        );
                    @endphp
                    <div class="bg-gray-950 border border-gray-800 rounded-lg p-4"
                         x-data="{ open: {{ $weight > 0 ? 'true' : 'false' }} }">
                        <div class="flex items-center gap-3 cursor-pointer" @click="open = !open">
                            <i class="fa-solid fa-chevron-right text-[10px] text-gray-600 transition-transform"
                               :class="open && 'rotate-90'"></i>
                            <code class="text-xs font-mono text-sky-400">{{ $axisKey }}</code>
                            <span class="text-sm text-gray-300">{{ $meta['label'] }}</span>
                            <span class="text-xs text-gray-600">{{ $meta['unit'] }}</span>
                            <span class="text-xs text-gray-600 ml-auto">[{{ $meta['min'] }} – {{ $meta['max'] }}]</span>
                            @if ($weight > 0)
                                <span class="text-xs font-mono text-emerald-400">poids {{ $weight }}</span>
                            @endif
                        </div>

                        <div x-show="open" x-cloak class="mt-3 pl-6 space-y-3">
                            <div class="flex items-center gap-4">
                                <label class="text-xs text-gray-500 w-20">Poids</label>
                                <input type="number" name="axes[{{ $axisKey }}][weight]"
                                       value="{{ $weight }}"
                                       min="0" max="100"
                                       class="w-24 px-2.5 py-1.5 text-sm bg-gray-900 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                                <span class="text-xs text-gray-600">/ 100</span>
                            </div>

                            <div>
                                <label class="text-xs text-gray-500 block mb-1">
                                    Courbe de scoring <span class="text-gray-600">(JSON — points {value, score})</span>
                                </label>
                                <textarea name="axes[{{ $axisKey }}][scoring_curve]"
                                          rows="6"
                                          placeholder='[{"value": 0, "score": 0}, {"value": 50, "score": 100}]'
                                          class="w-full px-3 py-2 text-xs font-mono bg-gray-900 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40 placeholder:text-gray-700">{{ $curve }}</textarea>
                                @error("axes.{$axisKey}.scoring_curve")
                                    <p class="text-xs text-red-400 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="text-[10px] text-gray-600">
                                Plage de valeurs attendue : <strong>{{ $meta['min'] }}</strong> à <strong>{{ $meta['max'] }}</strong> {{ $meta['unit'] }}.
                                Score : 0 (nul) à 100 (parfait). Interpolation linéaire entre les points.
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 p-3 bg-gray-900 border border-gray-800 rounded-lg text-xs text-gray-500" x-show="totalWeight() !== 100">
                <i class="fa-solid fa-triangle-exclamation text-amber-400"></i>
                Somme des poids : <strong x-text="totalWeight()" class="text-amber-400"></strong> — attendu : 100.
            </div>
        </x-admin.section>

        <div class="flex items-center gap-3">
            <x-admin.button type="submit" variant="primary" icon="fa-solid fa-check">
                {{ $isEdit ? 'Enregistrer' : 'Créer le profil' }}
            </x-admin.button>
            <x-admin.button href="{{ route('admin.sites.settings', ['tab' => 'scoring']) }}" variant="secondary">
                Annuler
            </x-admin.button>
        </div>
    </form>
</div>

@push('scripts')
<script>
function profileForm() {
    return {
        init() {},
        totalWeight() {
            let sum = 0;
            document.querySelectorAll('input[name$="[weight]"]').forEach(el => {
                sum += parseInt(el.value) || 0;
            });
            return sum;
        }
    };
}
</script>
@endpush

@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush
@endsection
