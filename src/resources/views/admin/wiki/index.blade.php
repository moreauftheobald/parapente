@extends('layouts.admin')
@section('title', 'Aide / Wiki')

@section('content')
<div class="max-w-5xl">
    <div class="flex items-baseline justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold text-white">Aide / Wiki</h1>
            <p class="text-sm text-gray-500 mt-1">
                Pages publiées sur <code class="text-gray-400">/aide</code>
                ({{ $pages->total() }} au total).
            </p>
        </div>
        <a href="{{ route('admin.wiki.create') }}"
           class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-400 text-white text-xs font-medium rounded-md transition shrink-0">
            <i class="fa-solid fa-plus"></i> Nouvelle page
        </a>
    </div>

    @if ($pages->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-700 bg-gray-900/40 p-10 text-center text-gray-500">
            Aucune page pour l'instant.
        </div>
    @else
        <div class="rounded-xl border border-gray-800 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 text-gray-500 text-xs uppercase tracking-wider">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Titre</th>
                        <th class="text-left px-4 py-2 font-medium w-40">Parent</th>
                        <th class="text-left px-4 py-2 font-medium w-24">Ordre</th>
                        <th class="text-left px-4 py-2 font-medium w-28">Statut</th>
                        <th class="px-4 py-2 w-32"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach ($pages as $p)
                        <tr class="bg-gray-950/40 hover:bg-gray-900/60 transition">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.wiki.edit', $p) }}" class="text-gray-100 hover:text-sky-300 font-medium">
                                    {{ $p->title }}
                                </a>
                                <div class="text-xs text-gray-600 mt-0.5">
                                    <code>/aide/{{ $p->slug }}</code>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-400">
                                {{ $p->parent?->title ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-gray-400 font-mono text-xs">{{ $p->sort_order }}</td>
                            <td class="px-4 py-3">
                                @if ($p->is_published)
                                    <span class="inline-flex items-center gap-1.5 text-xs text-emerald-400">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Publiée
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-600"></span> Brouillon
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3 text-xs">
                                    <a href="{{ route('wiki.show', $p->slug) }}" target="_blank" class="text-gray-400 hover:text-sky-300 transition" title="Voir sur le site">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    <form method="POST" action="{{ route('admin.wiki.toggle', $p) }}">
                                        @csrf
                                        <button type="submit" class="text-gray-400 hover:text-white transition"
                                                title="{{ $p->is_published ? 'Dépublier' : 'Publier' }}">
                                            <i class="fa-solid {{ $p->is_published ? 'fa-eye-slash' : 'fa-eye' }}"></i>
                                        </button>
                                    </form>
                                    <a href="{{ route('admin.wiki.edit', $p) }}" class="text-gray-400 hover:text-sky-300 transition" title="Éditer">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="POST" action="{{ route('admin.wiki.destroy', $p) }}"
                                          onsubmit="return confirm('Supprimer cette page ?');">
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

        <div class="mt-4">{{ $pages->links() }}</div>
    @endif
</div>
@endsection
