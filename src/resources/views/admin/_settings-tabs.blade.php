{{-- Onglets réutilisables pour les pages Paramètres de chaque section.
     Variables attendues :
       $tab          — onglet actif ('general', 'data', 'logs', …)
       $baseRoute    — nom de la route (ex: 'admin.data.index')
       $tabs         — (optionnel) liste personnalisée des onglets
                       Format : ['key' => ['label' => '…', 'icon' => 'fa-…'], …]
--}}
@php
    $tabs = $tabs ?? [
        'general' => ['label' => 'Général',          'icon' => 'fa-solid fa-sliders'],
        'data'    => ['label' => 'Data',              'icon' => 'fa-solid fa-cloud-arrow-down'],
        'logs'    => ['label' => 'Log / Monitoring',  'icon' => 'fa-solid fa-scroll'],
    ];
    $activeCls  = 'border-sky-500 text-sky-300';
    $defaultCls = 'border-transparent text-gray-500 hover:text-gray-300 hover:border-gray-600';
@endphp

<div class="flex gap-1 border-b border-gray-800 mb-6 overflow-x-auto">
    @foreach ($tabs as $key => $t)
        <a href="{{ route($baseRoute, ['tab' => $key]) }}"
           class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition whitespace-nowrap {{ $tab === $key ? $activeCls : $defaultCls }}">
            <i class="{{ $t['icon'] }} text-xs"></i>
            {{ $t['label'] }}
        </a>
    @endforeach
</div>
