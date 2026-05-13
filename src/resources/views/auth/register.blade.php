<x-app-shell title="Inscription" page-title="Inscription">
    <div class="max-w-md mx-auto mt-12 px-4">
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 shadow-xl">
            <h1 class="text-xl font-semibold text-white mb-1">Créer un compte</h1>
            <p class="text-sm text-gray-500 mb-6">Inscription gratuite — l'accès aux fonctionnalités utilisateurs (journal de vol, scoring perso, …).</p>

            <form method="POST" action="{{ route('register') }}" class="flex flex-col gap-4">
                @csrf

                <div>
                    <label for="name" class="block text-xs font-medium text-gray-400 mb-1">Nom complet <span class="text-gray-600">*</span></label>
                    <input id="name" name="name" type="text" required autofocus
                           value="{{ old('name') }}" autocomplete="name" maxlength="120"
                           class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                    @error('name')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="pseudo" class="block text-xs font-medium text-gray-400 mb-1">Pseudo <span class="text-gray-600">(optionnel)</span></label>
                    <input id="pseudo" name="pseudo" type="text"
                           value="{{ old('pseudo') }}" autocomplete="username" maxlength="40"
                           class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                    <p class="text-[11px] text-gray-600 mt-1">Lettres, chiffres, tirets, points, soulignés — 2 à 40 caractères.</p>
                    @error('pseudo')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-xs font-medium text-gray-400 mb-1">Email <span class="text-gray-600">*</span></label>
                    <input id="email" name="email" type="email" required
                           value="{{ old('email') }}" autocomplete="email" maxlength="191"
                           class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                    @error('email')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-xs font-medium text-gray-400 mb-1">Mot de passe <span class="text-gray-600">*</span></label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                    <p class="text-[11px] text-gray-600 mt-1">8 caractères minimum, dont au moins une lettre et un chiffre.</p>
                    @error('password')
                        <p class="text-red-400 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-xs font-medium text-gray-400 mb-1">Confirmation du mot de passe <span class="text-gray-600">*</span></label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                           class="w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40">
                </div>

                <button type="submit"
                        class="mt-2 w-full py-2 rounded-md bg-sky-500 hover:bg-sky-400 text-white font-medium text-sm transition">
                    Créer mon compte
                </button>
            </form>

            <p class="text-center text-xs text-gray-500 mt-6">
                Déjà un compte ?
                <a href="{{ route('login') }}" class="text-sky-400 hover:text-sky-300 transition">Se connecter</a>
            </p>
        </div>
    </div>
</x-app-shell>
