{{-- Bandeau « Rétention & profondeur » — barres de couverture par flux.
     Variable attendue : $bars (array de barres, cf. DataCoverage::*RetentionBars()).
     Un segment = un jour (plus ancien à gauche → aujourd'hui à droite).
     Couleur = présence (vert complet / orange partiel / gris absent),
     trait = borne de rétention, hachuré = jour en cours. --}}
@php
    $segClass = fn (array $s): string => match ($s['state']) {
        'green'   => 'bg-emerald-500/80',
        'orange'  => 'bg-amber-500/80',
        'pending' => 'bg-gray-600',
        default   => 'bg-gray-800',
    };
    $segTitle = function (array $s, bool $sampled = false): string {
        if ($s['is_today']) return $s['date'] . ' — en cours (jour incomplet)';
        if ($s['state'] === 'gray') return $s['date'] . ' — aucune donnée';
        $units = $s['units'] . '/' . $s['units_total'] . ' unités' . ($sampled ? ' (à 12h)' : '');
        return $s['date'] . ' — ' . $s['hours'] . ' h/24 · ' . $units;
    };
@endphp

@if (! empty($bars))
<div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
    <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-1">
        <i class="fa-solid fa-calendar-check text-emerald-400"></i> Rétention &amp; profondeur
    </h2>
    <p class="text-[11px] text-gray-600 mb-4">
        Un segment = un jour, du plus ancien (gauche) à aujourd'hui (droite). Le trait marque la borne de rétention —
        à sa gauche = devrait être purgé.
        <span class="text-emerald-400">■</span> complet ·
        <span class="text-amber-400">■</span> partiel ·
        <span class="text-gray-500">■</span> absent.
    </p>

    <div class="space-y-5">
        @foreach ($bars as $bar)
            <div x-data="{ open: false }">
                <div class="flex items-center justify-between mb-1 gap-3">
                    <div class="text-sm text-gray-200">
                        {{ $bar['label'] }}
                        <span class="text-xs text-gray-500">· rétention {{ $bar['retention_days'] }} j</span>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($bar['verdict']['empty'])
                            <x-admin.badge status="neutral">Aucune unité active</x-admin.badge>
                        @else
                            @if ($bar['verdict']['complete'] && ! $bar['verdict']['purge_late'])
                                <x-admin.badge status="success">{{ $bar['retention_days'] }} j complets</x-admin.badge>
                            @endif
                            @if ($bar['verdict']['has_hole'])
                                <x-admin.badge status="warning">Trou</x-admin.badge>
                            @endif
                            @if ($bar['verdict']['purge_late'])
                                <x-admin.badge status="danger">Purge en retard (J{{ $bar['oldest_offset'] }})</x-admin.badge>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- Barre principale --}}
                <div class="flex gap-px h-5 rounded overflow-hidden bg-gray-950">
                    @foreach ($bar['segments'] as $s)
                        <div class="flex-1 {{ $segClass($s) }} @if (! $s['beyond'] && $loop->index > 0 && $bar['segments'][$loop->index - 1]['beyond']) rb-boundary @endif @if ($s['is_today']) rb-today @endif"
                             title="{{ $segTitle($s, $bar['sampled'] ?? false) }}"></div>
                    @endforeach
                </div>

                <div class="flex items-center justify-between mt-1">
                    <div class="text-[11px] text-gray-500">
                        @if ($bar['oldest_offset'] !== null)
                            plus ancienne : J{{ $bar['oldest_offset'] }}
                        @else
                            aucune donnée
                        @endif
                        @if ($bar['last_at'])
                            · dernière : {{ $bar['last_at']->diffForHumans() }}
                        @endif
                    </div>
                    @if (! empty($bar['breakdown']))
                        <button type="button" @click="open = ! open" class="text-[11px] text-sky-400 hover:underline">
                            <i class="fa-solid" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                            <span x-text="open ? 'masquer le détail' : 'détail par {{ $bar['key'] === 'archive' ? 'modèle' : 'réseau' }}'"></span>
                        </button>
                    @endif
                </div>

                {{-- Détail dépliable (par modèle ou par réseau) --}}
                @if (! empty($bar['breakdown']))
                    <div x-show="open" x-cloak class="mt-2 pl-3 border-l border-gray-800 space-y-1.5">
                        @foreach ($bar['breakdown'] as $bd)
                            <div class="flex items-center gap-2">
                                <div class="w-32 shrink-0 text-[11px] text-gray-400 truncate" title="{{ $bd['label'] }}">{{ $bd['label'] }}</div>
                                <div class="flex gap-px h-3 rounded overflow-hidden bg-gray-950 flex-1">
                                    @foreach ($bd['segments'] as $s)
                                        <div class="flex-1 {{ $segClass($s) }} @if ($s['is_today']) rb-today @endif" title="{{ $segTitle($s, $bar['sampled'] ?? false) }}"></div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>

@push('styles')<style>
    [x-cloak]{display:none!important}
    /* Jour en cours : hachuré pour signaler « incomplet par nature ». */
    .rb-today{background-image:repeating-linear-gradient(45deg,rgba(255,255,255,.30) 0 2px,transparent 2px 4px)}
    /* Borne de rétention : trait à gauche du 1er jour encore conservé. */
    .rb-boundary{box-shadow:inset 2px 0 0 0 rgba(125,211,252,.75)}
</style>@endpush
@endif
