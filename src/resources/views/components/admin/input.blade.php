@props([
    'name'   => null,
    'label'  => null,
    'type'   => 'text',
    'value'  => null,
    'hint'   => null,
    'error'  => null,
    'suffix' => null,
    'wrapperClass' => '',
])

{{--
    Champ de formulaire BackOffice — disposition inline : Label [?] [saisie] [suffixe]
    L'aide (hint) apparaît en infobulle sur l'icône « ? ».

    Usage :
      <x-admin.input name="title" label="Titre" required maxlength="200" />
      <x-admin.input name="precip" label="Seuil orange" type="number"
                     hint="Consensus de précipitations au-delà duquel..." />
      <x-admin.input name="speed" label="Vitesse" type="number" suffix="km/h" />
--}}

@php
    $id      = $attributes->get('id') ?: $name;
    $val     = $value ?? old($name);
    $errMsg  = $error ?? ($name && $errors->has($name) ? $errors->first($name) : null);
    $baseCls = 'w-full px-2.5 py-1.5 bg-gray-950 border border-gray-700 text-gray-100 text-sm focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $inputCls = $baseCls . ($suffix ? ' rounded-l-md' : ' rounded-md');
@endphp

<div class="{{ $wrapperClass }}">
    <div class="flex items-center gap-2">
        @if ($label)
            <label for="{{ $id }}" class="shrink-0 text-sm text-gray-400 flex items-center gap-1">
                {{ $label }}
                <x-admin.tooltip :text="$hint" />
            </label>
        @endif
        @if ($suffix)
            <div class="flex flex-1 min-w-0">
                <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $val }}"
                       {{ $attributes->except(['id'])->merge(['class' => $inputCls]) }}>
                <span class="shrink-0 px-2 py-1.5 bg-gray-800 border border-l-0 border-gray-700 rounded-r-md text-xs text-gray-500">{{ $suffix }}</span>
            </div>
        @else
            <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ $val }}"
                   {{ $attributes->except(['id'])->merge(['class' => $inputCls]) }}>
        @endif
    </div>

    @if ($errMsg)
        <p class="text-red-400 text-xs mt-0.5 pl-0">{{ $errMsg }}</p>
    @endif
</div>
