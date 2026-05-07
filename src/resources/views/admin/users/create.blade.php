@extends('layouts.admin')
@section('title', 'Nouvel utilisateur')

@php
    $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $labelCls = 'block text-xs font-medium text-gray-400 mb-1';
    $errorCls = 'text-red-400 text-xs mt-1';
@endphp

@section('content')
<div class="max-w-xl">
    <div class="flex items-baseline justify-between mb-6">
        <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
            <i class="fa-solid fa-user-plus text-emerald-400"></i> Nouvel utilisateur
        </h1>
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

    <form method="POST" action="{{ route('admin.users.store') }}" class="bg-gray-900 border border-emerald-500/20 rounded-xl p-5 flex flex-col gap-4">
        @csrf

        <div>
            <label class="{{ $labelCls }}">Nom <span class="text-red-400">*</span></label>
            <input name="name" type="text" required class="{{ $inputCls }}" value="{{ old('name') }}" autofocus>
            @error('name')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelCls }}">Email <span class="text-red-400">*</span></label>
            <input name="email" type="email" required class="{{ $inputCls }}" value="{{ old('email') }}">
            @error('email')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="{{ $labelCls }}">Rôle <span class="text-red-400">*</span></label>
            <select name="role" required class="{{ $inputCls }}">
                <option value="user"  @selected(old('role') === 'user')>User</option>
                <option value="admin" @selected(old('role') === 'admin')>Admin</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Seuls les admins peuvent accéder au BackOffice.</p>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="{{ $labelCls }}">Mot de passe <span class="text-red-400">*</span></label>
                <input name="password" type="password" required minlength="8" class="{{ $inputCls }}">
                @error('password')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelCls }}">Confirmation <span class="text-red-400">*</span></label>
                <input name="password_confirmation" type="password" required minlength="8" class="{{ $inputCls }}">
            </div>
        </div>
        <p class="text-xs text-gray-500 -mt-2">Minimum 8 caractères.</p>

        <div class="flex items-center justify-between mt-2">
            <button type="submit" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-400 text-white text-sm font-medium rounded-md transition">
                <i class="fa-solid fa-floppy-disk"></i> Créer
            </button>
            <a href="{{ route('admin.users.index') }}" class="text-sm text-gray-400 hover:text-white transition">Annuler</a>
        </div>
    </form>
</div>
@endsection
