<x-app-shell title="Mon profil" page-title="Mon profil" detail-title="Sections" :left-default="true">

    {{-- Panneau gauche : navigation interne (ancres) --}}
    <x-slot:detail>
        <nav class="flex flex-col gap-1 text-sm">
            <a href="#identity"    class="px-3 py-2 rounded-md text-gray-300 hover:bg-gray-800 hover:text-white transition"><i class="fa-solid fa-id-badge w-5 text-center opacity-70"></i> Identité</a>
            <a href="#password"    class="px-3 py-2 rounded-md text-gray-300 hover:bg-gray-800 hover:text-white transition"><i class="fa-solid fa-key w-5 text-center opacity-70"></i> Mot de passe</a>
            <a href="#scorings"    class="px-3 py-2 rounded-md text-gray-300 hover:bg-gray-800 hover:text-white transition"><i class="fa-solid fa-sliders w-5 text-center opacity-70"></i> Scorings perso</a>
            @if (! $user->isAdmin())
                <a href="#danger"  class="px-3 py-2 rounded-md text-red-300/80 hover:bg-red-500/10 hover:text-red-300 transition mt-4"><i class="fa-solid fa-triangle-exclamation w-5 text-center opacity-70"></i> Supprimer mon compte</a>
            @endif
        </nav>
    </x-slot:detail>

    @php
        $inputCls = 'w-full px-3 py-2 bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
        $labelCls = 'block text-xs font-medium text-gray-400 mb-1';
        $errorCls = 'text-red-400 text-xs mt-1';
        $cardCls  = 'bg-gray-900 border border-gray-800 rounded-2xl p-6 shadow-xl';
    @endphp

    <div class="max-w-3xl mx-auto px-4 py-6 flex flex-col gap-6">

        {{-- En-tête --}}
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-full bg-sky-500/15 text-sky-300 flex items-center justify-center text-2xl">
                <i class="fa-solid fa-circle-user"></i>
            </div>
            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-white truncate">{{ $user->displayName() }}</h1>
                <p class="text-sm text-gray-500 truncate">{{ $user->email }}</p>
            </div>
            @if ($user->isAdmin())
                <span class="ml-auto px-2 py-1 text-[11px] rounded-md bg-amber-500/15 text-amber-300 border border-amber-500/30 uppercase tracking-wider">Admin</span>
            @endif
        </div>

        {{-- ─── Identité ──────────────────────────────────────────── --}}
        <section id="identity" class="{{ $cardCls }}">
            <h2 class="text-lg font-semibold text-white mb-1">Identité</h2>
            <p class="text-sm text-gray-500 mb-5">Tes informations personnelles affichées sur la plateforme.</p>

            <form method="POST" action="{{ route('user.profile.update') }}" class="flex flex-col gap-4">
                @csrf
                @method('PATCH')

                <div>
                    <label for="name" class="{{ $labelCls }}">Nom complet</label>
                    <input id="name" name="name" type="text" required maxlength="120"
                           value="{{ old('name', $user->name) }}" class="{{ $inputCls }}">
                    @error('name')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="pseudo" class="{{ $labelCls }}">Pseudo</label>
                    <input id="pseudo" name="pseudo" type="text" maxlength="40"
                           value="{{ old('pseudo', $user->pseudo) }}" class="{{ $inputCls }}">
                    <p class="text-[11px] text-gray-600 mt-1">Affiché sur le scoring perso, le journal de vol… Lettres, chiffres, tirets, points, soulignés.</p>
                    @error('pseudo')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="email" class="{{ $labelCls }}">Email</label>
                    <input id="email" name="email" type="email" required maxlength="191"
                           value="{{ old('email', $user->email) }}" class="{{ $inputCls }}">
                    @error('email')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="bio" class="{{ $labelCls }}">Bio <span class="text-gray-600">(optionnel)</span></label>
                    <textarea id="bio" name="bio" rows="3" maxlength="2000"
                              class="{{ $inputCls }} resize-y">{{ old('bio', $user->bio) }}</textarea>
                    @error('bio')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div class="flex justify-end">
                    <button type="submit"
                            class="px-4 py-2 rounded-md bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium transition">
                        Enregistrer
                    </button>
                </div>
            </form>
        </section>

        {{-- ─── Mot de passe ──────────────────────────────────────── --}}
        <section id="password" class="{{ $cardCls }}">
            <h2 class="text-lg font-semibold text-white mb-1">Mot de passe</h2>
            <p class="text-sm text-gray-500 mb-5">Choisis un mot de passe robuste — 8 caractères minimum, lettres et chiffres.</p>

            <form method="POST" action="{{ route('user.password.update') }}" class="flex flex-col gap-4">
                @csrf
                @method('PATCH')

                <div>
                    <label for="current_password" class="{{ $labelCls }}">Mot de passe actuel</label>
                    <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                           class="{{ $inputCls }}">
                    @error('current_password', 'updatePassword')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="new_password" class="{{ $labelCls }}">Nouveau mot de passe</label>
                    <input id="new_password" name="password" type="password" required autocomplete="new-password"
                           class="{{ $inputCls }}">
                    @error('password', 'updatePassword')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="password_confirmation" class="{{ $labelCls }}">Confirmation</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                           class="{{ $inputCls }}">
                </div>

                <div class="flex justify-end">
                    <button type="submit"
                            class="px-4 py-2 rounded-md bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium transition">
                        Mettre à jour
                    </button>
                </div>
            </form>
        </section>

        {{-- ─── Scorings perso (placeholder lot 2) ────────────────── --}}
        <section id="scorings" class="{{ $cardCls }}">
            <h2 class="text-lg font-semibold text-white mb-1">Mes scorings perso</h2>
            <p class="text-sm text-gray-500 mb-5">Définis tes propres conditions de vol favorables pour chaque site.</p>

            <div class="flex items-center gap-3 px-4 py-6 bg-gray-950/50 border border-dashed border-gray-700 rounded-lg text-gray-400 text-sm">
                <i class="fa-solid fa-helmet-safety text-2xl text-gray-600"></i>
                <div>
                    <p class="text-gray-300">Bientôt disponible.</p>
                    <p class="text-xs text-gray-500 mt-1">Tu pourras créer jusqu'à 10 scorings actifs en parallèle (cf. <code class="text-gray-400">FF_personnal_scoring.md</code>).</p>
                </div>
            </div>
        </section>

        {{-- ─── Suppression compte ────────────────────────────────── --}}
        @if (! $user->isAdmin())
            <section id="danger" class="{{ $cardCls }} border-red-900/50">
                <h2 class="text-lg font-semibold text-red-300 mb-1">Supprimer mon compte</h2>
                <p class="text-sm text-gray-500 mb-5">Cette action est <strong class="text-red-300">irréversible</strong>. Toutes tes données associées seront supprimées définitivement.</p>

                <form method="POST" action="{{ route('user.profile.destroy') }}" class="flex flex-col gap-4"
                      onsubmit="return confirm('Supprimer définitivement ton compte ? Cette action est irréversible.');">
                    @csrf
                    @method('DELETE')

                    <div>
                        <label for="delete_password" class="{{ $labelCls }}">Confirme avec ton mot de passe</label>
                        <input id="delete_password" name="password" type="password" required autocomplete="current-password"
                               class="{{ $inputCls }}">
                        @error('password', 'deleteAccount')<p class="{{ $errorCls }}">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex justify-end">
                        <button type="submit"
                                class="px-4 py-2 rounded-md bg-red-600 hover:bg-red-500 text-white text-sm font-medium transition">
                            Supprimer mon compte
                        </button>
                    </div>
                </form>
            </section>
        @endif

    </div>
</x-app-shell>
