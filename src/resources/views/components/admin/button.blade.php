@props([
    'variant' => 'primary',          // primary | secondary | danger | ghost
    'size'    => 'md',               // sm | md
    'href'    => null,               // si fourni : <a> au lieu de <button>
    'type'    => 'button',           // type pour <button> (button | submit)
    'icon'    => null,               // classe(s) FontAwesome ('fa-solid fa-plus') OU HTML brut (emoji / SVG inline)
    'disabled' => false,
])

{{--
    Bouton standardisé pour le BackOffice. Évite la dérive des classes Tailwind
    copiées-collées entre les ~26 vues admin (~50 boutons).

    Variants (alignés sur la palette admin existante) :
      - primary   : action principale (sky-500)
      - secondary : action secondaire (gray-800)
      - danger    : action destructive (red-500)
      - ghost     : action discrète (transparent + border)

    Tailles :
      - sm : compact (text-xs, px-3 py-1.5)
      - md : standard (text-sm, px-4 py-2)

    Usage :
      <x-admin.button href="{{ route('admin.sites.create') }}">+ Créer un site</x-admin.button>
      <x-admin.button type="submit" variant="danger">Supprimer</x-admin.button>
      <x-admin.button variant="ghost" size="sm">Réinitialiser</x-admin.button>
--}}

@php
    $base = 'inline-flex items-center justify-center gap-2 font-medium rounded-md transition disabled:opacity-50 disabled:cursor-not-allowed';

    $sizeClasses = match ($size) {
        'sm'    => 'px-3 py-1.5 text-xs',
        default => 'px-4 py-2 text-sm',
    };

    $variantClasses = match ($variant) {
        'secondary' => 'bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700',
        'danger'    => 'bg-red-500/15 border border-red-500/40 text-red-300 hover:bg-red-500/25 hover:text-red-200',
        'ghost'     => 'bg-transparent border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white',
        default     => 'bg-sky-500 hover:bg-sky-400 text-white',
    };

    $cls = trim("{$base} {$sizeClasses} {$variantClasses}");

    // Détection : si l'icône est une chaîne FA (commence par "fa-"), on wrap automatiquement
    // dans <i class="..."></i>. Sinon (emoji, SVG inline, autre HTML) on rend tel quel.
    $iconIsFa = $icon && is_string($icon) && str_starts_with(trim($icon), 'fa-');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $cls]) }}>
        @if ($icon)
            @if ($iconIsFa)<i class="{{ $icon }}"></i>@else{!! $icon !!}@endif
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->merge(['class' => $cls]) }}>
        @if ($icon)
            @if ($iconIsFa)<i class="{{ $icon }}"></i>@else{!! $icon !!}@endif
        @endif
        {{ $slot }}
    </button>
@endif
