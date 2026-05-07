<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BackOffice') · ParapenteFR</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Font Awesome 6 (CDN) — utilisé pour les icônes d'action --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100 h-screen flex flex-col overflow-hidden">

    {{-- Topbar --}}
    <nav class="bg-gray-900 border-b border-gray-800 px-4 py-2 flex items-center gap-6 shrink-0 z-50">
        <span class="font-bold text-sky-400 text-lg tracking-tight">⛶ ParapenteFR</span>
        <span class="text-xs uppercase tracking-wider text-gray-500 px-2 py-0.5 rounded border border-gray-700">BackOffice</span>
        <div class="flex-1"></div>

        @auth
            <a href="{{ route('map') }}" class="text-sm text-gray-400 hover:text-white transition">↩ Carte</a>
            <span class="text-sm text-gray-400">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-400 hover:text-red-400 transition">Déconnexion</button>
            </form>
        @endauth
    </nav>

    <div class="flex-1 flex overflow-hidden">
        @auth
            {{-- Sidebar --}}
            <aside class="w-56 bg-gray-900 border-r border-gray-800 flex flex-col shrink-0 overflow-y-auto">
                <nav class="p-3 flex flex-col gap-1 text-sm">
                    @php
                        $section = request()->segment(2) ?? 'dashboard';
                        $linkClass = fn ($active) => $active
                            ? 'flex items-center gap-3 px-3 py-2 rounded-md bg-sky-500/15 text-sky-300 border border-sky-500/30'
                            : 'flex items-center gap-3 px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-gray-100 transition';
                    @endphp

                    <a href="{{ route('admin.dashboard') }}" class="{{ $linkClass($section === 'dashboard' || request()->path() === 'admin') }}">
                        <span class="w-5 text-center">▣</span> Dashboard
                    </a>

                    <div class="mt-3 px-3 text-[10px] uppercase tracking-widest text-gray-600">Données</div>
                    <a href="{{ route('admin.sites.index') }}" class="{{ $linkClass($section === 'sites') }}">
                        <span class="w-5 text-center">⛰</span> Sites
                    </a>
                    <a href="{{ route('admin.balises.index') }}" class="{{ $linkClass($section === 'balises') }}">
                        <span class="w-5 text-center">🪁</span> Balises
                    </a>
                    <a href="#" class="{{ $linkClass($section === 'models') }} opacity-50 pointer-events-none" title="à venir">
                        <span class="w-5 text-center">☁</span> Modèles météo
                    </a>

                    <div class="mt-3 px-3 text-[10px] uppercase tracking-widest text-gray-600">Système</div>
                    <a href="{{ route('admin.users.index') }}" class="{{ $linkClass($section === 'users') }}">
                        <span class="w-5 text-center">👤</span> Utilisateurs
                    </a>
                    <a href="#" class="{{ $linkClass($section === 'logs') }} opacity-50 pointer-events-none" title="à venir">
                        <span class="w-5 text-center">📜</span> Logs / monitoring
                    </a>
                    <a href="#" class="{{ $linkClass($section === 'settings') }} opacity-50 pointer-events-none" title="à venir">
                        <span class="w-5 text-center">⚙</span> Paramètres
                    </a>
                </nav>
            </aside>
        @endauth

        {{-- Contenu --}}
        <main class="flex-1 overflow-y-auto p-6">
            @if (session('status'))
                <div class="mb-4 px-4 py-2 rounded bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-sm">
                    {{ session('status') }}
                </div>
            @endif
            @yield('content')
        </main>
    </div>

</body>
</html>
