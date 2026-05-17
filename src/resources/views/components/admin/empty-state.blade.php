@props([
    'icon'    => null,    // classes FA (ex: 'fa-solid fa-folder-open')
    'message' => null,    // alternative au slot
])

{{--
    État vide cohérent ("Aucun X pour l'instant"). Unifie les 3 variantes
    qui coexistaient (dashed, ligne de tableau, blocs génériques).

    Usage :
      <x-admin.empty-state message="Aucun article pour l'instant." />

      <x-admin.empty-state icon="fa-solid fa-mountain-sun">
          Aucun site n'est encore enregistré.
          <x-slot:actions>
              <x-admin.button href="...">+ Ajouter un site</x-admin.button>
          </x-slot:actions>
      </x-admin.empty-state>
--}}

<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-gray-700 bg-gray-900/40 p-10 text-center']) }}>
    @if ($icon)
        <div class="text-gray-600 text-3xl mb-3">
            <i class="{{ $icon }}"></i>
        </div>
    @endif
    <p class="text-gray-500">{{ $message ?? $slot }}</p>
    @if (isset($actions))
        <div class="mt-4 flex items-center justify-center gap-2">{{ $actions }}</div>
    @endif
</div>
