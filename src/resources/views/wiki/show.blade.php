@push('styles')
<style>
    .wiki-content { line-height: 1.7; }
    .wiki-content > :first-child { margin-top: 0; }
    .wiki-content h1, .wiki-content h2, .wiki-content h3, .wiki-content h4 {
        font-weight: 600; color: #f1f5f9; line-height: 1.3; margin: 1.4em 0 .5em;
    }
    .wiki-content h1 { font-size: 1.5rem; }
    .wiki-content h2 { font-size: 1.25rem; }
    .wiki-content h3 { font-size: 1.1rem; }
    .wiki-content p  { margin: .8em 0; }
    .wiki-content ul, .wiki-content ol { margin: .8em 0; padding-left: 1.4em; }
    .wiki-content ul { list-style: disc; }
    .wiki-content ol { list-style: decimal; }
    .wiki-content li { margin: .2em 0; }
    .wiki-content a  { color: #38bdf8; text-decoration: underline; }
    .wiki-content img { max-width: 100%; height: auto; border-radius: .5rem; margin: 1em 0; }
    .wiki-content figure { margin: 1em 0; }
    .wiki-content figcaption { font-size: .8rem; color: #94a3b8; text-align: center; margin-top: .4em; }
    .wiki-content blockquote { border-left: 3px solid #334155; padding-left: 1em; color: #94a3b8; font-style: italic; margin: 1em 0; }
    .wiki-content table { border-collapse: collapse; width: 100%; margin: 1em 0; }
    .wiki-content th, .wiki-content td { border: 1px solid #334155; padding: .4em .7em; text-align: left; }
    .wiki-content pre { background: #0f172a; border: 1px solid #1e293b; border-radius: .5rem; padding: .8em 1em; overflow-x: auto; }
    .wiki-content code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .9em; }
    .wiki-content hr { border: 0; border-top: 1px solid #1e293b; margin: 1.5em 0; }
</style>
@endpush

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
            <div class="wiki-content text-gray-300">{!! $page->body !!}</div>

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
