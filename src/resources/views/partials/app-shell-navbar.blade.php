{{--
    Barre de menu principale du shell global (<x-app-shell>).
    Inclus via @include — partage le scope Alpine (leftOpen / rightOpen).
    Variables attendues : $pageTitle, $hasDetail, $hasHelp.
--}}
@php
    $pageTitle ??= null;
    $hasDetail ??= false;
    $hasHelp   ??= false;

    $modules     = \App\Support\Navigation::modules();
    $moduleUrl   = fn (\App\Models\Module $m): ?string => $m->route_name && \Illuminate\Support\Facades\Route::has($m->route_name) ? route($m->route_name) : null;
    $moduleActive = fn (\App\Models\Module $m): bool => $m->route_name !== null && request()->routeIs($m->route_name);
@endphp

<nav class="relative shrink-0 h-14 bg-gray-900 border-b border-gray-800 px-2 sm:px-4 flex items-center gap-1 sm:gap-3 z-50">

    {{-- Bouton panneau détail (gauche) --}}
    @if ($hasDetail)
        <button type="button" @click="leftOpen = !leftOpen"
                :class="leftOpen ? 'bg-sky-500/20 text-sky-300' : 'text-gray-400 hover:text-gray-100 hover:bg-gray-800'"
                class="w-9 h-9 rounded-md flex items-center justify-center transition shrink-0"
                title="Panneau de détail" aria-label="Afficher le panneau de détail">
            <i class="fa-solid fa-bars"></i>
        </button>
    @endif

    {{-- Logo --}}
    <a href="{{ route('home') }}" class="flex items-center gap-2 font-bold text-sky-400 text-lg tracking-tight shrink-0 px-1">
        <span aria-hidden="true">⛶</span>
        <span class="hidden sm:inline">Qui Vole ?</span>
    </a>

    @if ($pageTitle)
        <span class="hidden lg:inline text-gray-600">/</span>
        <span class="hidden lg:inline text-sm text-gray-400">{{ $pageTitle }}</span>
    @endif

    {{-- Liens modules — desktop --}}
    <div class="hidden md:flex items-center gap-0.5 ml-2">
        @foreach ($modules as $module)
            @php($url = $moduleUrl($module))
            @if ($url)
                <a href="{{ $url }}"
                   @class([
                       'flex items-center gap-2 px-3 py-1.5 rounded-md text-sm transition',
                       'bg-sky-500/15 text-sky-300' => $moduleActive($module),
                       'text-gray-400 hover:text-gray-100 hover:bg-gray-800' => ! $moduleActive($module),
                   ])>
                    <i class="{{ $module->icon }} text-xs opacity-80"></i>{{ $module->label }}
                </a>
            @else
                <span class="flex items-center gap-2 px-3 py-1.5 rounded-md text-sm text-gray-600 cursor-default"
                      title="Bientôt disponible">
                    <i class="{{ $module->icon }} text-xs"></i>{{ $module->label }}
                </span>
            @endif
        @endforeach
    </div>

    {{-- Liens modules — mobile (menu déroulant) --}}
    <div class="md:hidden relative" x-data="{ open: false }">
        <button type="button" @click="open = !open"
                class="w-9 h-9 rounded-md flex items-center justify-center text-gray-300 hover:bg-gray-800 transition"
                aria-label="Modules">
            <i class="fa-solid fa-grip"></i>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.left
             class="absolute left-0 mt-2 w-56 bg-gray-900 border border-gray-800 rounded-lg shadow-xl py-1 z-50">
            @foreach ($modules as $module)
                @php($url = $moduleUrl($module))
                @if ($url)
                    <a href="{{ $url }}"
                       class="flex items-center gap-3 px-4 py-2 text-sm {{ $moduleActive($module) ? 'text-sky-300' : 'text-gray-300 hover:bg-gray-800' }}">
                        <i class="{{ $module->icon }} w-4 text-center"></i>{{ $module->label }}
                    </a>
                @else
                    <span class="flex items-center gap-3 px-4 py-2 text-sm text-gray-600">
                        <i class="{{ $module->icon }} w-4 text-center"></i>{{ $module->label }}
                    </span>
                @endif
            @endforeach
        </div>
    </div>

    <div class="flex-1"></div>

    {{-- Authentification --}}
    @auth
        @php($authUser = auth()->user())
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open"
                    class="flex items-center gap-2 px-2 sm:px-3 h-9 rounded-md text-sm text-gray-300 hover:bg-gray-800 transition">
                <i class="fa-solid fa-circle-user text-base"></i>
                <span class="hidden sm:inline max-w-[10rem] truncate">{{ $authUser->displayName() }}</span>
                <i class="fa-solid fa-chevron-down text-[10px] opacity-60"></i>
            </button>
            <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.right
                 class="absolute right-0 mt-2 w-56 bg-gray-900 border border-gray-800 rounded-lg shadow-xl py-1 z-50 text-sm">
                <div class="px-4 py-2 text-gray-500 border-b border-gray-800 truncate">{{ $authUser->email }}</div>

                <a href="{{ route('user.profile') }}"
                   class="flex items-center gap-3 px-4 py-2 text-gray-300 hover:bg-gray-800 hover:text-white transition">
                    <i class="fa-solid fa-id-badge w-4 text-center"></i>Mon profil
                </a>
                <a href="{{ route('user.scorings') }}"
                   class="flex items-center gap-3 px-4 py-2 text-gray-300 hover:bg-gray-800 hover:text-white transition">
                    <i class="fa-solid fa-sliders w-4 text-center"></i>Mes scorings perso
                </a>
                <a href="{{ route('user.hidden-sites') }}"
                   class="flex items-center gap-3 px-4 py-2 text-gray-300 hover:bg-gray-800 hover:text-white transition">
                    <i class="fa-solid fa-eye-slash w-4 text-center"></i>Sites masqués
                </a>

                @if ($authUser->isAdmin())
                    <a href="{{ route('admin.dashboard') }}"
                       class="flex items-center gap-3 px-4 py-2 text-gray-300 hover:bg-gray-800 hover:text-white transition">
                        <i class="fa-solid fa-gauge-high w-4 text-center"></i>Administration
                    </a>
                @endif

                <form method="POST" action="{{ route('logout') }}" class="border-t border-gray-800 mt-1 pt-1">
                    @csrf
                    <button type="submit" class="w-full flex items-center gap-3 px-4 py-2 text-gray-300 hover:bg-gray-800 hover:text-red-400 transition text-left">
                        <i class="fa-solid fa-right-from-bracket w-4 text-center"></i>Déconnexion
                    </button>
                </form>
            </div>
        </div>
    @else
        <div class="relative" x-data="{ open: false }">
            <button type="button" @click="open = !open"
                    class="flex items-center gap-2 px-3 h-9 rounded-md text-sm bg-sky-500/15 text-sky-300 hover:bg-sky-500/25 transition">
                <i class="fa-solid fa-right-to-bracket"></i>
                <span class="hidden sm:inline">Connexion</span>
            </button>
            <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.right
                 class="absolute right-0 mt-2 w-72 bg-gray-900 border border-gray-800 rounded-lg shadow-xl p-4 z-50">
                <form method="POST" action="{{ route('login') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label for="nav-email" class="block text-xs text-gray-400 mb-1">Email</label>
                        <input id="nav-email" type="email" name="email" required autocomplete="email"
                               class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-1.5 text-sm text-gray-100 focus:border-sky-500 outline-none">
                    </div>
                    <div>
                        <label for="nav-password" class="block text-xs text-gray-400 mb-1">Mot de passe</label>
                        <input id="nav-password" type="password" name="password" required autocomplete="current-password"
                               class="w-full bg-gray-950 border border-gray-700 rounded-md px-3 py-1.5 text-sm text-gray-100 focus:border-sky-500 outline-none">
                    </div>
                    <label class="flex items-center gap-2 text-xs text-gray-400 select-none">
                        <input type="checkbox" name="remember" value="1" class="rounded border-gray-700 bg-gray-950 text-sky-500"> Se souvenir de moi
                    </label>
                    @error('email')
                        <p class="text-xs text-red-400">{{ $message }}</p>
                    @enderror
                    <button type="submit"
                            class="w-full bg-sky-500 hover:bg-sky-400 text-white text-sm font-medium rounded-md px-3 py-1.5 transition">
                        Se connecter
                    </button>
                </form>
                <p class="text-center text-xs text-gray-500 mt-3 pt-3 border-t border-gray-800">
                    Pas encore inscrit ?
                    <a href="{{ route('register') }}" class="text-sky-400 hover:text-sky-300 transition">Créer un compte</a>
                </p>
            </div>
        </div>
    @endauth

    {{-- Bouton panneau aide (droite) --}}
    @if ($hasHelp)
        <button type="button" @click="rightOpen = !rightOpen"
                :class="rightOpen ? 'bg-sky-500/20 text-sky-300' : 'text-gray-400 hover:text-gray-100 hover:bg-gray-800'"
                class="w-9 h-9 rounded-md flex items-center justify-center transition shrink-0"
                title="Aide / légende" aria-label="Afficher le panneau d'aide">
            <i class="fa-solid fa-circle-question"></i>
        </button>
    @endif
</nav>
