@extends('layouts.admin')
@section('title', 'Édition · ' . $user->name)

@section('content')
<div class="max-w-xl">
    <div class="bg-gradient-to-r from-violet-500/10 via-gray-900 to-gray-900 border border-violet-500/20 rounded-xl p-5 mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
                <i class="fa-solid fa-user-pen text-violet-400"></i> {{ $user->name }}
                @if ($isSelf)
                    <x-admin.badge status="info" :dot="false">vous</x-admin.badge>
                @endif
            </h1>
            <p class="text-xs text-gray-500 mt-1 font-mono">
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">#{{ $user->id }}</span>
                <span class="text-gray-600">· créé le {{ $user->created_at->format('d/m/Y') }}</span>
            </p>
        </div>
        <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-400 hover:text-white transition">
            <i class="fa-solid fa-arrow-left"></i> Retour
        </a>
    </div>

    @if ($errors->any())
        <x-admin.alert type="error">
            <strong>Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </x-admin.alert>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="bg-gray-900 border border-violet-500/20 rounded-xl p-5 flex flex-col gap-4">
        @csrf
        @method('PATCH')

        <x-admin.input name="name" label="Nom *" :value="old('name', $user->name)" required />
        <x-admin.input name="email" label="Email *" type="email" :value="old('email', $user->email)" required />

        <x-admin.field name="role" label="Rôle *">
            <select id="role" name="role" required @disabled($isSelf)
                    class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                <option value="user"  @selected(old('role', $user->role) === 'user')>User</option>
                <option value="admin" @selected(old('role', $user->role) === 'admin')>Admin</option>
            </select>
            @if ($isSelf)
                <input type="hidden" name="role" value="{{ $user->role }}">
                <p class="text-xs text-amber-300 mt-1">
                    <i class="fa-solid fa-lock"></i> Vous ne pouvez pas modifier votre propre rôle.
                </p>
            @endif
        </x-admin.field>

        <div class="border-t border-gray-800 pt-4">
            <h3 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
                <i class="fa-solid fa-key"></i> Changer le mot de passe
            </h3>
            <p class="text-xs text-gray-500 mb-3">Laisser vide pour ne pas changer le mot de passe actuel.</p>
            <div class="grid grid-cols-2 gap-3">
                <x-admin.input name="password" label="Nouveau mot de passe" type="password" minlength="8" />
                <x-admin.input name="password_confirmation" label="Confirmation" type="password" minlength="8" />
            </div>
        </div>

        <div class="flex items-center justify-between mt-2">
            <x-admin.button type="submit" variant="primary" icon="fa-solid fa-floppy-disk"
                            class="bg-gradient-to-r from-violet-500 to-violet-600 hover:from-violet-400 hover:to-violet-500 shadow-lg shadow-violet-500/20 border-0">
                Enregistrer
            </x-admin.button>
            <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>

    @unless ($isSelf)
        <div class="mt-8 bg-red-500/5 border border-red-500/30 rounded-xl p-5">
            <h3 class="text-sm font-semibold text-red-300 mb-2 flex items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation"></i> Zone dangereuse
            </h3>
            <div class="flex items-center justify-between">
                <p class="text-xs text-gray-500">Suppression définitive de cet utilisateur.</p>
                <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                      onsubmit="return confirm('Supprimer définitivement « {{ addslashes($user->name) }} » ?');">
                    @csrf
                    @method('DELETE')
                    <x-admin.button type="submit" variant="danger" icon="fa-solid fa-trash">Supprimer</x-admin.button>
                </form>
            </div>
        </div>
    @endunless
</div>
@endsection
