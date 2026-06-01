@extends('layouts.admin')
@section('title', 'Aide / Wiki')

@section('content')
<div>
    <x-admin.page-title title="Aide / Wiki">
        <x-slot:subtitle>
            Pages publiées sur <code class="text-gray-400">/aide</code> ({{ $pages->total() }} au total).
        </x-slot:subtitle>
        <x-slot:actions>
            <x-admin.button variant="primary" size="sm" :href="route('admin.wiki.create')" icon="fa-solid fa-plus">
                Nouvelle page
            </x-admin.button>
        </x-slot:actions>
    </x-admin.page-title>

    @if ($pages->isEmpty())
        <x-admin.empty-state icon="fa-solid fa-book-open" message="Aucune page pour l'instant." />
    @else
        <div class="rounded-xl border border-gray-800 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
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
                                    <x-admin.badge status="published">Publiée</x-admin.badge>
                                @else
                                    <x-admin.badge status="neutral">Brouillon</x-admin.badge>
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
