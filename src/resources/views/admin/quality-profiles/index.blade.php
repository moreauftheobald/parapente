@extends('layouts.admin')
@section('title', 'Profils qualité')

@section('content')
<div class="max-w-5xl">
    <x-admin.page-title title="Profils de qualité de vol">
        <x-slot:subtitle>Configuration des profils de scoring lus par le sidecar consensus.</x-slot:subtitle>
        <x-slot:actions>
            <x-admin.button href="{{ route('admin.quality-profiles.create') }}" variant="primary" icon="fa-solid fa-plus">
                Nouveau profil
            </x-admin.button>
        </x-slot:actions>
    </x-admin.page-title>

    @if ($profiles->isEmpty())
        <x-admin.empty-state icon="fa-solid fa-bullseye" message="Aucun profil de qualité configuré." />
    @else
        <div class="space-y-4">
            @foreach ($profiles as $profile)
                @php $totalWeight = $profile->axes->sum('weight'); @endphp
                <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <span class="text-base font-medium text-gray-100">{{ $profile->label }}</span>
                            <code class="text-xs font-mono text-gray-500 bg-gray-800 px-2 py-0.5 rounded">{{ $profile->slug }}</code>
                            @if ($profile->active)
                                <x-admin.badge status="active">Actif</x-admin.badge>
                            @else
                                <x-admin.badge status="neutral">Inactif</x-admin.badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <x-admin.button href="{{ route('admin.quality-profiles.edit', $profile) }}" variant="secondary" size="sm" icon="fa-solid fa-pen">
                                Modifier
                            </x-admin.button>
                            <form method="POST" action="{{ route('admin.quality-profiles.destroy', $profile) }}"
                                  onsubmit="return confirm('Supprimer « {{ $profile->label }} » ?')">
                                @csrf @method('DELETE')
                                <x-admin.button type="submit" variant="danger" size="sm" icon="fa-solid fa-trash">
                                    Supprimer
                                </x-admin.button>
                            </form>
                        </div>
                    </div>

                    @if ($profile->axes->isNotEmpty())
                        <div class="space-y-1">
                            @foreach ($profile->axes->sortByDesc('weight') as $axis)
                                @php
                                    $meta = $availableAxes[$axis->axis] ?? ['label' => $axis->axis, 'unit' => ''];
                                    $pct  = $totalWeight > 0 ? round($axis->weight / $totalWeight * 100) : 0;
                                    $pts  = is_array($axis->scoring_curve) ? count($axis->scoring_curve) : 0;
                                @endphp
                                <div class="flex items-center gap-3 text-sm">
                                    <div class="w-44 text-gray-300">{{ $meta['label'] }}</div>
                                    <div class="flex-1">
                                        <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
                                            <div class="h-full bg-sky-500/60 rounded-full" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                    <div class="w-20 text-right text-xs text-gray-500 font-mono">{{ $axis->weight }} ({{ $pct }}%)</div>
                                    <div class="w-16 text-xs text-gray-600 font-mono">{{ $pts }} pts</div>
                                </div>
                            @endforeach
                        </div>
                        @if ($totalWeight !== 100)
                            <div class="mt-2 text-xs text-amber-400">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                Somme des poids = {{ $totalWeight }} (attendu : 100)
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-gray-600 italic">Aucun axe configuré.</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
