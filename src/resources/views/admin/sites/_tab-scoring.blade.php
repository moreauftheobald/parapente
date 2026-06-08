{{--
    Onglet « Scoring (sidecar) » de la page Paramètres Sites.
    Liste les profils qualité et leurs axes configurés.

    Variables attendues :
      $qualityProfiles  — Collection de QualityProfile (avec axes eager-loaded)
      $availableAxes    — QualityAxis::AVAILABLE_AXES
--}}

<div class="flex items-center justify-between mb-6">
    <div>
        <h2 class="text-sm font-medium text-gray-200">Profils de qualité de vol</h2>
        <p class="text-xs text-gray-500 mt-1">
            Profils de scoring lus par le sidecar pour calculer un indice de qualité par site et créneau horaire.
            Chaque profil combine 7 axes météo pondérés, avec une courbe de scoring personnalisable par axe.
        </p>
    </div>
    <x-admin.button href="{{ route('admin.quality-profiles.create') }}" variant="primary" icon="fa-solid fa-plus" size="sm">
        Nouveau profil
    </x-admin.button>
</div>

@if ($qualityProfiles->isEmpty())
    <x-admin.empty-state icon="fa-solid fa-bullseye" message="Aucun profil de qualité configuré. Créez un premier profil pour que le sidecar puisse calculer des scores de qualité." />
@else
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        @foreach ($qualityProfiles as $profile)
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-sm text-gray-200">{{ $profile->label }}</span>
                        <code class="text-[10px] font-mono text-gray-500 bg-gray-800 px-1.5 py-0.5 rounded">{{ $profile->slug }}</code>
                        @if ($profile->active)
                            <x-admin.badge status="active">Actif</x-admin.badge>
                        @else
                            <x-admin.badge status="neutral">Inactif</x-admin.badge>
                        @endif
                    </div>
                    <div class="flex items-center gap-1">
                        <x-admin.button href="{{ route('admin.quality-profiles.edit', $profile) }}" variant="ghost" size="sm" icon="fa-solid fa-pen">
                            Modifier
                        </x-admin.button>
                        <form method="POST" action="{{ route('admin.quality-profiles.destroy', $profile) }}"
                              onsubmit="return confirm('Supprimer le profil « {{ $profile->label }} » ?')">
                            @csrf @method('DELETE')
                            <x-admin.button type="submit" variant="ghost" size="sm" icon="fa-solid fa-trash" class="text-red-400 hover:text-red-300">
                                Suppr.
                            </x-admin.button>
                        </form>
                    </div>
                </div>

                @if ($profile->axes->isEmpty())
                    <p class="text-xs text-gray-600 italic">Aucun axe configuré.</p>
                @else
                    @php
                        $totalWeight = $profile->axes->sum('weight');
                    @endphp
                    <div class="space-y-1.5">
                        @foreach ($profile->axes->sortByDesc('weight') as $axis)
                            @php
                                $meta = $availableAxes[$axis->axis] ?? ['label' => $axis->axis, 'unit' => ''];
                                $pct  = $totalWeight > 0 ? round($axis->weight / $totalWeight * 100) : 0;
                                $pts  = is_array($axis->scoring_curve) ? count($axis->scoring_curve) : 0;
                            @endphp
                            <div class="flex items-center gap-3 text-xs">
                                <div class="w-36 text-gray-400 truncate" title="{{ $meta['label'] }}">{{ $meta['label'] }}</div>
                                <div class="flex-1">
                                    <div class="h-1.5 bg-gray-800 rounded-full overflow-hidden">
                                        <div class="h-full bg-sky-500/60 rounded-full" style="width: {{ $pct }}%"></div>
                                    </div>
                                </div>
                                <div class="w-16 text-right text-gray-500 font-mono">{{ $axis->weight }} <span class="text-gray-600">({{ $pct }}%)</span></div>
                                <div class="w-20 text-gray-600 font-mono">{{ $pts }} pts</div>
                            </div>
                        @endforeach
                    </div>
                    @if ($totalWeight !== 100)
                        <div class="mt-2 text-[10px] text-amber-400">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Somme des poids = {{ $totalWeight }} (attendu : 100)
                        </div>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
@endif

<x-admin.section title="Axes disponibles" icon="fa-solid fa-layer-group" color="gray" class="mt-8">
    <p class="text-xs text-gray-500 mb-3">
        Les 7 axes que le sidecar calcule à partir des données consensus. Chaque axe est mappé en score 0-100 via une courbe configurable.
    </p>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
        @foreach ($availableAxes as $key => $meta)
            <div class="flex items-center gap-3 px-3 py-2 bg-gray-950 border border-gray-800 rounded text-xs">
                <code class="font-mono text-sky-400">{{ $key }}</code>
                <span class="text-gray-400">{{ $meta['label'] }}</span>
                <span class="text-gray-600 ml-auto">{{ $meta['unit'] }}</span>
            </div>
        @endforeach
    </div>
</x-admin.section>
