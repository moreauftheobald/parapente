@props([
    'title'    => null,        // alternative au slot par défaut
    'subtitle' => null,        // ligne de description sous le titre
])

{{--
    Titre normalisé d'une page BackOffice : h1 text-2xl + ligne descriptive
    optionnelle. Évite les variations de tailles/marges entre les écrans.

    Usage :
      <x-admin.page-title title="Sites" subtitle="14 sites en base" />

      <x-admin.page-title>
          Sites
          <x-slot:subtitle>14 sites en base</x-slot:subtitle>
          <x-slot:actions>
              <x-admin.button href="...">+ Nouveau</x-admin.button>
          </x-slot:actions>
      </x-admin.page-title>
--}}

<div {{ $attributes->merge(['class' => 'mb-6 flex items-start justify-between gap-4 flex-wrap']) }}>
    <div>
        <h1 class="text-2xl font-semibold text-white">
            {{ $title ?? $slot }}
        </h1>
        @if ($subtitle ?? false)
            <p class="text-sm text-gray-500 mt-1">{{ $subtitle }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex items-center gap-2 shrink-0">{{ $actions }}</div>
    @endif
</div>
