@props([
    'type' => 'success',   // success | error | warning | info
    'icon' => null,        // override icône (sinon icône par défaut du type)
])

{{--
    Bandeau d'alerte cohérent (messages flash, info contextuelle).
    Unifie les paddings/iconographies disparates.

    Usage typique au-dessus d'une vue :
      @if (session('status'))
          <x-admin.alert type="success">{{ session('status') }}</x-admin.alert>
      @endif
      <x-admin.alert type="warning">Le scoring n'a pas été lancé depuis 3h.</x-admin.alert>
--}}

@php
    $palette = match ($type) {
        'error'   => ['bg' => 'bg-red-500/10',     'border' => 'border-red-500/40',     'text' => 'text-red-300',     'icon' => 'fa-solid fa-circle-exclamation'],
        'warning' => ['bg' => 'bg-amber-500/10',   'border' => 'border-amber-500/40',   'text' => 'text-amber-300',   'icon' => 'fa-solid fa-triangle-exclamation'],
        'info'    => ['bg' => 'bg-sky-500/10',     'border' => 'border-sky-500/40',     'text' => 'text-sky-300',     'icon' => 'fa-solid fa-circle-info'],
        default   => ['bg' => 'bg-emerald-500/10', 'border' => 'border-emerald-500/40', 'text' => 'text-emerald-300', 'icon' => 'fa-solid fa-circle-check'],
    };
    $iconCls = $icon ?? $palette['icon'];
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-3 px-4 py-3 rounded-md border {$palette['bg']} {$palette['border']} {$palette['text']} text-sm mb-4"]) }}>
    <i class="{{ $iconCls }} mt-0.5"></i>
    <div class="flex-1">{{ $slot }}</div>
</div>
