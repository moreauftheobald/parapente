@extends('layouts.admin')
@section('title', 'Historique des paramètres')

@section('content')
<div>
    <x-admin.page-title title="Historique des paramètres">
        <x-slot:subtitle>Audit trail de toutes les modifications de paramètres.</x-slot:subtitle>
        <x-slot:actions>
            <form method="GET" class="flex items-center gap-2">
                <input name="search" type="search" value="{{ request('search') }}" placeholder="Filtrer par clé…"
                       class="px-2.5 py-1.5 text-xs bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40 w-48">
                <x-admin.button type="submit" variant="primary" size="sm" icon="fa-solid fa-filter">Filtrer</x-admin.button>
            </form>
        </x-slot:actions>
    </x-admin.page-title>

    @if ($entries->isEmpty())
        <x-admin.empty-state icon="fa-solid fa-clock-rotate-left" message="Aucune modification enregistrée." />
    @else
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">Date</th>
                        <th class="px-3 py-2 text-left font-medium">Clé</th>
                        <th class="px-3 py-2 text-left font-medium">Ancienne valeur</th>
                        <th class="px-3 py-2 text-left font-medium">Nouvelle valeur</th>
                        <th class="px-3 py-2 text-left font-medium">Utilisateur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/70">
                    @foreach ($entries as $entry)
                        <tr class="hover:bg-gray-800/30 transition">
                            <td class="px-3 py-2 text-xs font-mono text-gray-400 whitespace-nowrap">
                                {{ $entry->changed_at->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-3 py-2 text-xs font-mono text-white">{{ $entry->setting_key }}</td>
                            <td class="px-3 py-2 text-xs font-mono text-gray-500 max-w-48 truncate" title="{{ $entry->old_value }}">
                                {{ Str::limit($entry->old_value, 80) ?: '—' }}
                            </td>
                            <td class="px-3 py-2 text-xs font-mono text-gray-300 max-w-48 truncate" title="{{ $entry->new_value }}">
                                {{ Str::limit($entry->new_value, 80) ?: '—' }}
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-400">
                                {{ $entry->user?->name ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $entries->links() }}
        </div>
    @endif
</div>
@endsection
