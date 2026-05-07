@extends('layouts.admin')
@section('title', 'Édition · ' . $user->name)

@php
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $labelCls = 'block text-xs font-medium text-gray-400 mb-1';
    $errorCls = 'text-red-400 text-xs mt-1';
@endphp

@section('content')
<div class="max-w-xl">
    <div class="bg-gradient-to-r from-violet-500/10 via-gray-900 to-gray-900 border border-violet-500/20 rounded-xl p-5 mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
                <i class="fa-solid fa-user-pen text-violet-400"></i> {{ $user->name }}
                @if ($isSelf)
                    <span class="text-xs text-sky-300 bg-sky-500/15 border border-sky-500/30 px-2 py-0.5 rounded">vous</span>
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
        <div class="mb-4 px-4 py-3 rounded bg-red-500/15 border border-red-500/30 text-red-300 text-sm">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> Quelques erreurs :</strong>
            <ul class="list-disc list-inside mt-1">
                @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="bg-gray-900 border border-violet-500/20 rounded-xl p-5 flex flex-col gap-4">
        @csrf
        @method('PATCH')

        <div>
            <label class="{{ $labelCls }}">Nom <span class="text-red-400">*</span></label>
            <input name="name" type="text" required class="{{ $inputCls }}" value="{{ old('name', $user->name) }}">
            @error('name')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelCls }}">Email <span class="text-red-400">*</span></label>
            <input name="email" type="email" required class="{{ $inputCls }}" value="{{ old('email', $user->email) }}">
            @error('email')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelCls }}">Rôle <span class="text-red-400">*</span></label>
            <select name="role" required class="{{ $inputCls }}" @disabled($isSelf)>
                <option value="user"  @selected(old('role', $user->role) === 'user')>User</option>
                <option value="admin" @selected(old('role', $user->role) === 'admin')>Admin</option>
            </select>
            @if ($isSelf)
                <input type="hidden" name="role" value="{{ $user->role }}">
                <p class="text-xs text-amber-300 mt-1">
                    <i class="fa-solid fa-lock"></i> Vous ne pouvez pas modifier votre propre rôle.
                </p>
            @endif
        </div>

        <div class="border-t border-gray-800 pt-4">
            <h3 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
                <i class="fa-solid fa-key"></i> Changer le mot de passe
            </h3>
            <p class="text-xs text-gray-500 mb-3">Laisser vide pour ne pas changer le mot de passe actuel.</p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $labelCls }}">Nouveau mot de passe</label>
                    <input name="password" type="password" minlength="8" class="{{ $inputCls }}">
                    @error('password')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelCls }}">Confirmation</label>
                    <input name="password_confirmation" type="password" minlength="8" class="{{ $inputCls }}">
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between mt-2">
            <button type="submit" class="px-4 py-2 bg-gradient-to-r from-violet-500 to-violet-600 hover:from-violet-400 hover:to-violet-500 text-white text-sm font-medium rounded-md transition shadow-lg shadow-violet-500/20">
                <i class="fa-solid fa-floppy-disk"></i> Enregistrer
            </button>
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
                    <button type="submit" class="px-4 py-2 bg-red-500/15 border border-red-500/40 text-red-300 hover:bg-red-500/25 hover:text-red-200 text-sm rounded-md transition flex items-center gap-2">
                        <i class="fa-solid fa-trash"></i> Supprimer
                    </button>
                </form>
            </div>
        </div>
    @endunless
</div>
@endsection
