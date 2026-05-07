@extends('layouts.admin')
@section('title', 'Utilisateurs')

@php
    $sortLink = function (string $field, string $label) use ($sort, $dir) {
        $newDir = ($sort === $field && $dir === 'asc') ? 'desc' : 'asc';
        $arrow  = $sort === $field ? ($dir === 'asc' ? '↑' : '↓') : '';
        $params = array_merge(request()->query(), ['sort' => $field, 'dir' => $newDir]);
        return sprintf(
            '<a href="?%s" class="text-gray-300 hover:text-white">%s <span class="text-sky-400">%s</span></a>',
            http_build_query($params), e($label), $arrow
        );
    };

    $inputCls  = 'w-full mt-1 px-2 py-1 text-xs bg-gray-950 border border-gray-700 rounded text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $selectCls = $inputCls . ' cursor-pointer';
@endphp

@section('content')
<div class="max-w-5xl">
    <div class="flex items-baseline justify-between mb-4">
        <div>
            <h1 class="text-2xl font-semibold text-white">Utilisateurs</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ number_format($users->total(), 0, ',', ' ') }} utilisateur{{ $users->total() > 1 ? 's' : '' }}
            </p>
        </div>
        <div class="flex items-center gap-3">
            @if (request()->query())
                <a href="{{ route('admin.users.index') }}" class="text-xs text-gray-400 hover:text-white transition" title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </a>
            @endif
            <button form="filters-form" type="submit"
                    class="px-3 py-1.5 bg-sky-500 hover:bg-sky-400 text-white text-xs font-medium rounded-md transition">
                <i class="fa-solid fa-filter"></i> Appliquer
            </button>
            <a href="{{ route('admin.users.create') }}"
               class="px-3 py-1.5 bg-emerald-500 hover:bg-emerald-400 text-white text-xs font-medium rounded-md transition">
                <i class="fa-solid fa-user-plus"></i> Nouvel utilisateur
            </a>
        </div>
    </div>

    <form id="filters-form" method="GET"></form>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-950 text-xs uppercase tracking-wider border-b border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left align-top">
                        {!! $sortLink('name', 'Nom / Email') !!}
                        <input form="filters-form" name="search" type="search"
                               value="{{ request('search') }}" placeholder="Rechercher…"
                               class="{{ $inputCls }}">
                    </th>
                    <th class="px-4 py-3 text-left align-top w-40">
                        {!! $sortLink('role', 'Rôle') !!}
                        <select form="filters-form" name="role" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            <option value="admin" @selected(request('role') === 'admin')>Admin</option>
                            <option value="user"  @selected(request('role') === 'user')>User</option>
                        </select>
                    </th>
                    <th class="px-4 py-3 text-left align-top w-44">
                        {!! $sortLink('created_at', 'Créé le') !!}
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($users as $u)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <div class="text-gray-100 font-medium flex items-center gap-2">
                                {{ $u->name }}
                                @if ($u->id === auth()->id())
                                    <span class="text-xs text-sky-300 bg-sky-500/15 border border-sky-500/30 px-1.5 py-0.5 rounded">vous</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 font-mono">{{ $u->email }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($u->role === 'admin')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded bg-violet-500/15 text-violet-300 border border-violet-500/30">
                                    <i class="fa-solid fa-shield-halved"></i> Admin
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded bg-gray-800 text-gray-400 border border-gray-700">
                                    <i class="fa-solid fa-user"></i> User
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-400 text-xs font-mono">
                            {{ $u->created_at->format('d/m/Y H:i') }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <a href="{{ route('admin.users.edit', $u) }}"
                                   class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white transition"
                                   title="Éditer">
                                    <i class="fa-solid fa-pen text-xs"></i>
                                </a>
                                @if ($u->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="inline"
                                          onsubmit="return confirm('Supprimer définitivement « {{ addslashes($u->name) }} » ?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="w-8 h-8 inline-flex items-center justify-center rounded border border-red-500/40 text-red-300 hover:bg-red-500/10 transition"
                                                title="Supprimer">
                                            <i class="fa-solid fa-trash text-xs"></i>
                                        </button>
                                    </form>
                                @else
                                    <span class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-800 text-gray-700"
                                          title="Vous ne pouvez pas vous supprimer vous-même">
                                        <i class="fa-solid fa-trash text-xs"></i>
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center text-gray-500">Aucun utilisateur.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
@endsection
