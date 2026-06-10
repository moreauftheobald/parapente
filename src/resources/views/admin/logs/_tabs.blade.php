{{-- Onglets partagés des écrans Logs. Variable attendue : $active (jobs|sidecar|laravel) --}}
@php
    $logTabs = [
        'jobs'    => ['route' => route('admin.logs.jobs'),    'icon' => 'fa-solid fa-clipboard-list', 'label' => 'Jobs'],
        'sidecar' => ['route' => route('admin.logs.sidecar'), 'icon' => 'fa-solid fa-satellite-dish', 'label' => 'Runs sidecar'],
        'laravel' => ['route' => route('admin.logs.index'),   'icon' => 'fa-solid fa-scroll',         'label' => 'Logs Laravel'],
    ];
@endphp
<div class="flex gap-1 mb-5 border-b border-gray-800">
    @foreach ($logTabs as $key => $t)
        @if ($key === $active)
            <span class="px-4 py-2 text-sm text-sky-300 border-b-2 border-sky-500 -mb-px">
                <i class="{{ $t['icon'] }} mr-1"></i> {{ $t['label'] }}
            </span>
        @else
            <a href="{{ $t['route'] }}" class="px-4 py-2 text-sm text-gray-400 hover:text-gray-200">
                <i class="{{ $t['icon'] }} mr-1"></i> {{ $t['label'] }}
            </a>
        @endif
    @endforeach
</div>
