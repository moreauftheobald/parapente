@extends('layouts.admin')
@section('title', 'Articles / Changelog')

@section('content')
<div class="max-w-4xl">
    <x-admin.page-title title="Articles / Changelog">
        <x-slot:subtitle>
            Affichés sur la page d'accueil, du plus récent au plus ancien ({{ $articles->total() }} au total).
        </x-slot:subtitle>
        <x-slot:actions>
            <x-admin.button variant="primary" size="sm" :href="route('admin.articles.create')" icon="fa-solid fa-plus">
                Nouvel article
            </x-admin.button>
        </x-slot:actions>
    </x-admin.page-title>

    @if ($articles->isEmpty())
        <x-admin.empty-state icon="fa-solid fa-newspaper" message="Aucun article pour l'instant." />
    @else
        <div class="rounded-xl border border-gray-800 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Titre</th>
                        <th class="text-left px-4 py-2 font-medium w-44">Date de publication</th>
                        <th class="text-left px-4 py-2 font-medium w-28">Statut</th>
                        <th class="px-4 py-2 w-32"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach ($articles as $article)
                        <tr class="bg-gray-950/40 hover:bg-gray-900/60 transition">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('admin.articles.edit', $article) }}" class="text-gray-100 hover:text-sky-300 font-medium">
                                        {{ $article->title }}
                                    </a>
                                    @if ($article->is_pinned)
                                        <x-admin.badge status="warning" icon="fa-solid fa-thumbtack" :dot="false">Épinglé</x-admin.badge>
                                    @endif
                                </div>
                                @if ($article->author)
                                    <div class="text-xs text-gray-600 mt-0.5">par {{ $article->author->name }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-400">
                                {{ optional($article->published_at)->format('d/m/Y H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($article->is_published)
                                    <x-admin.badge status="published">Publié</x-admin.badge>
                                @else
                                    <x-admin.badge status="neutral">Brouillon</x-admin.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3 text-xs">
                                    <form method="POST" action="{{ route('admin.articles.toggle', $article) }}">
                                        @csrf
                                        <button type="submit" class="text-gray-400 hover:text-white transition"
                                                title="{{ $article->is_published ? 'Dépublier' : 'Publier' }}">
                                            <i class="fa-solid {{ $article->is_published ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                        </button>
                                    </form>
                                    <a href="{{ route('admin.articles.edit', $article) }}" class="text-gray-400 hover:text-sky-300 transition" title="Éditer">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="POST" action="{{ route('admin.articles.destroy', $article) }}"
                                          onsubmit="return confirm('Supprimer cet article ?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-gray-400 hover:text-red-400 transition" title="Supprimer">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $articles->links() }}</div>
    @endif
</div>
@endsection
