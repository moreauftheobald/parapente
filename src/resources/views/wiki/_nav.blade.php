{{--
    Navigation arborescente de l'aide en ligne.
    Variables attendues :
      - $roots : Collection<WikiPage> racines (avec children eager-loaded)
      - $page  : WikiPage|null — page courante (pour mise en évidence)
--}}
@php
    $currentId = $page?->id;
    $activeAncestors = $page ? collect($page->ancestors())->pluck('id')->all() : [];
    $linkBase  = 'block px-3 py-1.5 rounded-md text-sm transition';
    $linkIdle  = 'text-gray-400 hover:bg-gray-800 hover:text-gray-100';
    $linkOn    = 'bg-sky-500/15 text-sky-300 border border-sky-500/30';
    $linkOpen  = 'text-gray-200';
@endphp

@if ($roots->isEmpty())
    <p class="text-sm text-gray-500 italic">L'aide est en cours de rédaction.</p>
@else
    <nav class="space-y-1">
        @foreach ($roots as $root)
            @php $isOn = $root->id === $currentId; @endphp
            <div>
                <a href="{{ route('wiki.show', $root->slug) }}"
                   class="{{ $linkBase }} {{ $isOn ? $linkOn : (in_array($root->id, $activeAncestors, true) ? $linkOpen : $linkIdle) }}">
                    <i class="fa-solid fa-folder-tree w-4 text-center text-gray-500"></i>
                    {{ $root->title }}
                </a>
                @if ($root->children->isNotEmpty())
                    <div class="ml-5 mt-1 space-y-0.5 border-l border-gray-800 pl-3">
                        @foreach ($root->children as $child)
                            @php $childOn = $child->id === $currentId; @endphp
                            <a href="{{ route('wiki.show', $child->slug) }}"
                               class="{{ $linkBase }} {{ $childOn ? $linkOn : $linkIdle }}">
                                <i class="fa-regular fa-file-lines w-4 text-center text-gray-600"></i>
                                {{ $child->title }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </nav>
@endif
