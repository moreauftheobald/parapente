@extends('layouts.admin')
@section('title', 'Nouvel utilisateur')

@section('content')
<div class="max-w-xl">
    <x-admin.page-title>
        <span class="flex items-center gap-2">
            <i class="fa-solid fa-user-plus text-emerald-400"></i> Nouvel utilisateur
        </span>
        <x-slot:actions>
            <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-400 hover:text-white transition">
                <i class="fa-solid fa-arrow-left"></i> Retour
            </a>
        </x-slot:actions>
    </x-admin.page-title>

    @if ($errors->any())
        <x-admin.alert type="error">
            <strong>Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </x-admin.alert>
    @endif

    <form method="POST" action="{{ route('admin.users.store') }}" class="bg-gray-900 border border-emerald-500/20 rounded-xl p-5 flex flex-col gap-4">
        @csrf

        <x-admin.input name="name" label="Nom *" required autofocus />
        <x-admin.input name="email" label="Email *" type="email" required />

        <x-admin.field name="role" label="Rôle *" hint="Seuls les admins peuvent accéder au BackOffice.">
            <select id="role" name="role" required
                    class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                <option value="user"  @selected(old('role') === 'user')>User</option>
                <option value="admin" @selected(old('role') === 'admin')>Admin</option>
            </select>
        </x-admin.field>

        <div class="grid grid-cols-2 gap-3">
            <x-admin.input name="password" label="Mot de passe *" type="password" required minlength="8" />
            <x-admin.input name="password_confirmation" label="Confirmation *" type="password" required minlength="8" />
        </div>
        <p class="text-xs text-gray-500 -mt-2">Minimum 8 caractères.</p>

        <div class="flex items-center justify-between mt-2">
            <x-admin.button type="submit" variant="primary" icon="fa-solid fa-floppy-disk">Créer</x-admin.button>
            <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>
</div>
@endsection
