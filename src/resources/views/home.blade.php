<x-app-shell title="Accueil" page-title="Accueil" help-title="Aide">

    <x-slot:help>
        <p class="text-gray-400 mb-4">
            Le panneau droit affiche selon les écrans l'aide, les légendes ou un menu d'actions.
        </p>
        <ul class="space-y-2 text-gray-500">
            <li><i class="fa-solid fa-circle-question w-4 text-center text-sky-400"></i> ouvre/ferme ce panneau</li>
            <li><kbd class="px-1.5 py-0.5 text-xs bg-gray-800 border border-gray-700 rounded">Échap</kbd> ferme les panneaux</li>
        </ul>
    </x-slot:help>

    <div class="w-full lg:w-4/5 mx-auto px-5 sm:px-8 py-8 sm:py-12">
        <header class="mb-8">
            <h1 class="text-2xl sm:text-3xl font-semibold text-white">Qui Vole ?</h1>
            <p class="text-gray-400 mt-1">Actualités &amp; nouveautés de la plateforme.</p>
        </header>

        @if ($pinned)
            <section class="mb-10 rounded-2xl border border-amber-500/30 bg-gradient-to-br from-amber-500/[0.07] to-gray-900/60 p-6 sm:p-8 shadow-[0_0_0_1px_rgba(245,158,11,0.08)]">
                <header class="mb-4 pb-4 border-b border-amber-500/20 flex items-start justify-between gap-4">
                    <div>
                        <div class="inline-flex items-center gap-1.5 text-[10px] uppercase tracking-wider text-amber-300/90 mb-2">
                            <i class="fa-solid fa-thumbtack"></i> À la une
                        </div>
                        <h2 class="text-xl sm:text-2xl font-semibold text-white">{{ $pinned->title }}</h2>
                    </div>
                </header>
                <div class="rich-content text-gray-200">{!! $pinned->body !!}</div>
            </section>
        @endif

        @if ($articles->isNotEmpty())
            <h3 class="text-xs uppercase tracking-wider text-gray-500 mb-3">Actualités récentes</h3>
            @foreach ($articles as $article)
                <article class="mb-6 rounded-2xl border border-gray-800 bg-gray-900/60 p-6 sm:p-8">
                    <header class="mb-4 pb-4 border-b border-gray-800">
                        <h2 class="text-xl font-semibold text-white">{{ $article->title }}</h2>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ optional($article->published_at)->format('d/m/Y') }}
                            @if ($article->author) · {{ $article->author->name }} @endif
                        </div>
                    </header>
                    {{-- Contenu HTML saisi par un administrateur via l'éditeur WYSIWYG --}}
                    <div class="rich-content text-gray-300">{!! $article->body !!}</div>
                </article>
            @endforeach
        @elseif (! $pinned)
            <div class="rounded-2xl border border-dashed border-gray-700 bg-gray-900/40 p-10 text-center text-gray-500 mb-6">
                Aucune actualité pour le moment.
            </div>
        @endif
    </div>

</x-app-shell>
