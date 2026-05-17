@props([
    'name'  => null,
    'label' => null,
    'hint'  => null,
    'error' => null,
])

{{--
    Wrapper de champ générique : label + slot libre (textarea, select,
    composé Alpine, etc.) + erreur + hint. À utiliser quand <x-admin.input>
    ne suffit pas. Le slot doit contenir le contrôle de saisie ; appliquer
    la classe CSS via @class(['admin-input', ...]) ou utiliser directement
    une classe Tailwind dérivée du même look.

    La classe Tailwind partagée pour <textarea>/<select> :
        w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md
        text-gray-100 text-sm focus:outline-none focus:border-sky-500
        focus:ring-1 focus:ring-sky-500/40

    Usage :
      <x-admin.field name="body" label="Contenu">
          <textarea name="body" class="...">{{ old('body') }}</textarea>
      </x-admin.field>
--}}

@php
    $errMsg = $error ?? ($name && $errors->has($name) ? $errors->first($name) : null);
@endphp

<div {{ $attributes }}>
    @if ($label)
        <label @if ($name) for="{{ $name }}" @endif class="block text-xs font-medium text-gray-400 mb-1">{{ $label }}</label>
    @endif

    {{ $slot }}

    @if ($hint && ! $errMsg)
        <p class="text-[11px] text-gray-600 mt-1">{{ $hint }}</p>
    @endif

    @if ($errMsg)
        <p class="text-red-400 text-xs mt-1">{{ $errMsg }}</p>
    @endif
</div>
