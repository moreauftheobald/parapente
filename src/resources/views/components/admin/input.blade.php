@props([
    'name'   => null,
    'label'  => null,
    'type'   => 'text',
    'value'  => null,             // valeur initiale (sinon old($name))
    'hint'   => null,             // ligne d'aide sous le champ
    'error'  => null,             // message d'erreur (sinon récupéré sur $errors)
    'wrapperClass' => '',
])

{{--
    Champ de formulaire BackOffice — wrap label + input + erreur + hint.
    Élimine la duplication de la classe `$inputCls` présente dans ~15 vues.

    Pour les types non gérés (select, textarea, checkbox), utiliser la classe
    de base via la constante CSS — passer `<x-admin.field>` à la place et
    fournir le contrôle en slot.

    Usage :
      <x-admin.input name="title" label="Titre" required maxlength="200" />
      <x-admin.input name="published_at" type="datetime-local"
                     label="Date de publication" hint="Détermine l'ordre" />
--}}

@php
    $id      = $attributes->get('id') ?: $name;
    $val     = $value ?? old($name);
    $errMsg  = $error ?? ($name && $errors->has($name) ? $errors->first($name) : null);
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 text-sm focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
@endphp

<div class="{{ $wrapperClass }}">
    @if ($label)
        <label for="{{ $id }}" class="block text-xs font-medium text-gray-400 mb-1">{{ $label }}</label>
    @endif

    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $val }}"
           {{ $attributes->except(['id', 'class'])->merge(['class' => $inputCls]) }}>

    @if ($hint && ! $errMsg)
        <p class="text-[11px] text-gray-600 mt-1">{{ $hint }}</p>
    @endif

    @if ($errMsg)
        <p class="text-red-400 text-xs mt-1">{{ $errMsg }}</p>
    @endif
</div>
