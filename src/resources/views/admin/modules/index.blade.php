@extends('layouts.admin')
@section('title', 'Modules')

@section('content')
<div class="max-w-4xl">
    <h1 class="text-2xl font-semibold text-white">Modules du menu</h1>
    <p class="text-sm text-gray-500 mt-1 mb-6">
        L'affichage de chaque module dans la barre de menu supérieure dépend de ces réglages :
        module actif, niveau de droit requis, et compte utilisateur obligatoire ou non.
    </p>

    <div class="space-y-3">
        @foreach ($modules as $module)
            <form method="POST" action="{{ route('admin.modules.update', $module) }}"
                  class="rounded-xl border border-gray-800 bg-gray-900/50 p-4 flex flex-col gap-4 sm:flex-row sm:items-center">
                @csrf
                @method('PATCH')

                {{-- Identité du module --}}
                <div class="flex items-center gap-3 sm:w-52 shrink-0">
                    <i class="{{ $module->icon ?: 'fa-solid fa-puzzle-piece' }} text-sky-400 w-5 text-center"></i>
                    <div class="min-w-0">
                        <div class="text-gray-100 font-medium truncate">{{ $module->label }}</div>
                        <div class="text-[11px] text-gray-600">
                            {{ $module->key }}@unless ($module->route_name) · <span class="text-amber-500/80">non implémenté</span>@endunless
                        </div>
                    </div>
                </div>

                {{-- Réglages --}}
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 flex-1">
                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" {{ $module->is_active ? 'checked' : '' }}
                               class="rounded border-gray-700 bg-gray-950 text-emerald-500 focus:ring-emerald-500/40">
                        Actif
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-400">
                        <span>Niveau de droit</span>
                        <select name="access_level"
                                class="px-2 py-1 text-sm bg-gray-950 border border-gray-700 rounded text-gray-100 focus:outline-none focus:border-sky-500 cursor-pointer">
                            <option value="guest" @selected($module->access_level === 'guest')>Public</option>
                            <option value="user"  @selected($module->access_level === 'user')>Utilisateur</option>
                            <option value="admin" @selected($module->access_level === 'admin')>Administrateur</option>
                        </select>
                    </label>

                    <label class="flex items-center gap-2 text-sm text-gray-300">
                        <input type="hidden" name="requires_registration" value="0">
                        <input type="checkbox" name="requires_registration" value="1" {{ $module->requires_registration ? 'checked' : '' }}
                               class="rounded border-gray-700 bg-gray-950 text-sky-500 focus:ring-sky-500/40">
                        Compte obligatoire
                    </label>
                </div>

                <button type="submit"
                        class="px-3 py-1.5 bg-sky-500 hover:bg-sky-400 text-white text-xs font-medium rounded-md transition shrink-0 self-start sm:self-auto">
                    Enregistrer
                </button>
            </form>
        @endforeach
    </div>

    <p class="text-xs text-gray-600 mt-6">
        Note : « Niveau de droit » s'appuie sur le rôle actuel des utilisateurs (admin / user).
        Un module en « non implémenté » apparaît grisé dans le menu tant qu'aucune route ne lui est associée.
    </p>
</div>
@endsection
