@extends('layouts.admin')
@section('title', 'Connexion')

@section('content')
    <div class="max-w-sm mx-auto mt-12">
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 shadow-xl">
            <h1 class="text-xl font-semibold text-white mb-1">Connexion</h1>
            <p class="text-sm text-gray-500 mb-6">Accès réservé aux administrateurs.</p>

            <form method="POST" action="{{ route('admin.login') }}" class="flex flex-col gap-4">
                @csrf

                <div>
                    <label for="email" class="block text-xs font-medium text-gray-400 mb-1">Email</label>
                    <input id="email" name="email" type="email" required autofocus
                           value="{{ old('email') }}"
                           class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                    @error('email')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-xs font-medium text-gray-400 mb-1">Mot de passe</label>
                    <input id="password" name="password" type="password" required
                           class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                    @error('password')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-400">
                    <input type="checkbox" name="remember" value="1" class="rounded border-gray-700 bg-gray-950 text-sky-500 focus:ring-sky-500/40">
                    Rester connecté
                </label>

                <button type="submit"
                        class="mt-2 w-full py-2 rounded-md bg-sky-500 hover:bg-sky-400 text-white font-medium text-sm transition">
                    Se connecter
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-gray-600 mt-4">
            <a href="{{ route('map') }}" class="hover:text-gray-400 transition">↩ Retour à la carte</a>
        </p>
    </div>
@endsection
