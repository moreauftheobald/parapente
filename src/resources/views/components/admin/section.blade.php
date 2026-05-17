@props([
    'title' => null,
    'icon'  => null,            // classes FA (ex: 'fa-solid fa-mountain-sun')
    'color' => 'gray',          // gray | sky | violet | emerald | amber | red
])

{{--
    Section thématique d'un écran (typiquement formulaire long découpé).
    Couleur d'accentuation pour les bordures + icône en tête.

    Usage :
      <x-admin.section title="Coordonnées" icon="fa-solid fa-location-dot" color="violet">
          <x-admin.input name="lat" label="Latitude" />
          <x-admin.input name="lng" label="Longitude" />
      </x-admin.section>
--}}

@php
    $palette = match ($color) {
        'sky'     => ['border' => 'border-sky-500/20',     'icon' => 'text-sky-300/80'],
        'violet'  => ['border' => 'border-violet-500/20',  'icon' => 'text-violet-300/80'],
        'emerald' => ['border' => 'border-emerald-500/20', 'icon' => 'text-emerald-300/80'],
        'amber'   => ['border' => 'border-amber-500/20',   'icon' => 'text-amber-300/80'],
        'red'     => ['border' => 'border-red-500/20',     'icon' => 'text-red-300/80'],
        default   => ['border' => 'border-gray-800',       'icon' => 'text-gray-400'],
    };
@endphp

<section {{ $attributes->merge(['class' => "rounded-xl border {$palette['border']} bg-gray-900/40 p-5 sm:p-6"]) }}>
    @if ($title || $icon)
        <header class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800/60">
            @if ($icon)
                <i class="{{ $icon }} {{ $palette['icon'] }}"></i>
            @endif
            @if ($title)
                <h2 class="text-sm font-semibold text-gray-200 uppercase tracking-wider">{{ $title }}</h2>
            @endif
        </header>
    @endif

    {{ $slot }}
</section>
