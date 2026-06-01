<x-app-shell :title="$page->title" page-title="Aide" detail-title="Sommaire" help-title="Sur cette page" :left-default="true">

    <x-slot:detail>
        @include('wiki._nav')
    </x-slot:detail>

    <x-slot:help>
        @php $ancestors = $page->ancestors(); @endphp
        @if (! empty($ancestors))
            <div class="text-xs text-gray-500 mb-3">
                <span class="uppercase tracking-wider text-[10px]">Fil d'Ariane</span>
                <div class="mt-1 space-y-1">
                    @foreach ($ancestors as $a)
                        <div><i class="fa-solid fa-angle-right text-gray-600 mr-1"></i><a href="{{ route('wiki.show', $a->slug) }}" class="text-gray-400 hover:text-sky-300">{{ $a->title }}</a></div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($page->author)
            <p class="text-xs text-gray-500">Rédigé par {{ $page->author->name }}</p>
        @endif
        <p class="text-xs text-gray-500 mt-1">
            Mis à jour le {{ optional($page->updated_at)->format('d/m/Y') }}
        </p>

        <a href="{{ route('wiki.index') }}" class="inline-flex items-center gap-1.5 text-sm text-sky-400 hover:text-sky-300 mt-4">
            <i class="fa-solid fa-arrow-left"></i> Retour au sommaire
        </a>
    </x-slot:help>

    <div class="w-full lg:w-4/5 mx-auto px-5 sm:px-8 py-8 sm:py-12">
        @php $ancestors = $page->ancestors(); @endphp
        @if (! empty($ancestors))
            <nav class="text-xs text-gray-500 mb-4 flex flex-wrap items-center gap-1.5">
                <a href="{{ route('wiki.index') }}" class="hover:text-sky-300">Aide</a>
                @foreach ($ancestors as $a)
                    <i class="fa-solid fa-angle-right text-gray-700"></i>
                    <a href="{{ route('wiki.show', $a->slug) }}" class="hover:text-sky-300">{{ $a->title }}</a>
                @endforeach
                <i class="fa-solid fa-angle-right text-gray-700"></i>
                <span class="text-gray-400">{{ $page->title }}</span>
            </nav>
        @endif

        <article class="rounded-2xl border border-gray-800 bg-gray-900/60 p-6 sm:p-10">
            <header class="mb-6 pb-4 border-b border-gray-800">
                <h1 class="text-2xl sm:text-3xl font-semibold text-white">{{ $page->title }}</h1>
                @if ($page->excerpt)
                    <p class="text-gray-400 mt-2">{{ $page->excerpt }}</p>
                @endif
            </header>

            {{-- HTML saisi par un administrateur via TinyMCE --}}
            <div class="rich-content text-gray-300">{!! $page->body !!}</div>

            @if ($page->children->isNotEmpty())
                <footer class="mt-8 pt-6 border-t border-gray-800">
                    <h2 class="text-xs uppercase tracking-wider text-gray-500 mb-3">Sous-pages</h2>
                    <ul class="space-y-2">
                        @foreach ($page->children as $child)
                            <li>
                                <a href="{{ route('wiki.show', $child->slug) }}" class="text-sky-400 hover:text-sky-300">
                                    <i class="fa-regular fa-file-lines mr-1"></i> {{ $child->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </footer>
            @endif
        </article>
    </div>

</x-app-shell>
