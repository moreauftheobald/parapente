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

<x-app-shell title="Aide" page-title="Aide" detail-title="Sommaire" help-title="À propos" :left-default="true">

    <x-slot:detail>
        @include('wiki._nav')
    </x-slot:detail>

    <x-slot:help>
        <p class="text-gray-400 mb-3">
            Cette section regroupe la documentation de la plateforme : comment lire la carte,
            d'où viennent les données, comment fonctionne le scoring, etc.
        </p>
        <p class="text-gray-500 text-sm">
            Les pages sont rédigées par l'équipe et mises à jour au fil des évolutions de
            Qui Vole.
        </p>
    </x-slot:help>

    <div class="w-full lg:w-4/5 mx-auto px-5 sm:px-8 py-8 sm:py-12">
        <header class="mb-8">
            <h1 class="text-2xl sm:text-3xl font-semibold text-white">Aide</h1>
            <p class="text-gray-400 mt-1">Documentation, fonctionnement et conseils d'utilisation.</p>
        </header>

        @if ($roots->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-700 bg-gray-900/40 p-10 text-center text-gray-500">
                L'aide est en cours de rédaction.
            </div>
        @else
            <div class="grid sm:grid-cols-2 gap-4">
                @foreach ($roots as $root)
                    <a href="{{ route('wiki.show', $root->slug) }}"
                       class="block rounded-2xl border border-gray-800 bg-gray-900/60 hover:bg-gray-900 hover:border-sky-500/40 transition p-5">
                        <div class="text-base font-semibold text-white mb-1">
                            <i class="fa-solid fa-folder-tree text-sky-400/80 mr-1.5"></i>
                            {{ $root->title }}
                        </div>
                        @if ($root->excerpt)
                            <p class="text-sm text-gray-400">{{ $root->excerpt }}</p>
                        @endif
                        @if ($root->children->isNotEmpty())
                            <div class="text-xs text-gray-500 mt-3">
                                {{ $root->children->count() }} sous-page{{ $root->children->count() > 1 ? 's' : '' }}
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </div>

</x-app-shell>
