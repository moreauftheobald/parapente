@props([
    'name'   => null,
    'label'  => null,
    'hint'   => null,
    'error'  => null,
    'inline' => true,
])

{{--
    Wrapper de champ générique : label + slot libre (textarea, select, etc.)
    Par défaut en ligne (inline=true). Passer :inline="false" pour les
    contrôles volumineux (textarea, TinyMCE).

    Usage :
      <x-admin.field name="parent_id" label="Page parente" hint="Texte d'aide">
          <select name="parent_id" class="...">...</select>
      </x-admin.field>
      <x-admin.field name="body" label="Contenu" :inline="false">
          <textarea ...></textarea>
      </x-admin.field>
--}}

@php
    $errMsg = $error ?? ($name && $errors->has($name) ? $errors->first($name) : null);
@endphp

<div {{ $attributes }}>
    @if ($inline)
        <div class="flex items-center gap-2">
            @if ($label)
                <label @if ($name) for="{{ $name }}" @endif class="shrink-0 text-sm text-gray-400 flex items-center gap-1">
                    {{ $label }}
                    <x-admin.tooltip :text="$hint" />
                </label>
            @endif
            {{ $slot }}
        </div>
    @else
        @if ($label)
            <label @if ($name) for="{{ $name }}" @endif class="text-sm text-gray-400 flex items-center gap-1 mb-1">
                {{ $label }}
                <x-admin.tooltip :text="$hint" />
            </label>
        @endif
        {{ $slot }}
    @endif

    @if ($errMsg)
        <p class="text-red-400 text-xs mt-0.5">{{ $errMsg }}</p>
    @endif
</div>
