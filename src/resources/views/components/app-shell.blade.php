@props([
    'title'        => null,   // titre de l'onglet navigateur
    'pageTitle'    => null,   // libellé affiché dans la barre du haut
    'detailTitle'  => 'Détail',
    'helpTitle'    => 'Aide',
    'leftDefault'  => false,  // panneau gauche ouvert d'emblée (desktop only)
    'rightDefault' => false,  // panneau droit ouvert d'emblée (desktop only)
])

@php
    // Un slot conditionnel (@auth / @hasSection) est extrait à la compilation
    // mais peut être vide à l'exécution : on teste le contenu réel.
    $hasDetail = isset($detail) && trim((string) $detail) !== '';
    $hasHelp   = isset($help)   && trim((string) $help)   !== '';
    $leftInit  = $hasDetail && $leftDefault  ? "window.matchMedia('(min-width: 1024px)').matches" : 'false';
    $rightInit = $hasHelp   && $rightDefault ? "window.matchMedia('(min-width: 1024px)').matches" : 'false';
@endphp

<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title . ' · ' : '' }}ParapenteFR</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Font Awesome 6 (icônes des modules / actions) --}}
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
          crossorigin="anonymous" referrerpolicy="no-referrer">

    @stack('styles')
</head>
<body class="h-full bg-gray-950 text-gray-100">

<div class="h-screen flex flex-col overflow-hidden"
     x-data="{ leftOpen: {{ $leftInit }}, rightOpen: {{ $rightInit }} }"
     @keydown.escape.window="leftOpen = false; rightOpen = false">

    {{-- ═══ Barre de menu ═══════════════════════════════════════════ --}}
    @include('partials.app-shell-navbar', [
        'pageTitle' => $pageTitle,
        'hasDetail' => $hasDetail,
        'hasHelp'   => $hasHelp,
    ])

    {{-- ═══ Corps : panneau gauche · contenu · panneau droit ═══════ --}}
    <div class="flex-1 flex overflow-hidden relative">

        {{-- Panneau latéral GAUCHE — détail --}}
        @if ($hasDetail)
            <div x-show="leftOpen" x-cloak x-transition.opacity @click="leftOpen = false"
                 class="absolute inset-0 bg-black/50 z-30 lg:hidden"></div>

            <aside x-show="leftOpen" x-cloak
                   x-transition:enter="transition transform ease-out duration-200"
                   x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition transform ease-in duration-150"
                   x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
                   class="absolute inset-y-0 left-0 border-r border-gray-800 z-40 lg:relative lg:z-auto
                          w-80 max-w-[85vw] bg-gray-900 shrink-0 flex flex-col shadow-2xl lg:shadow-none">
                <header class="shrink-0 h-11 px-4 flex items-center justify-between border-b border-gray-800">
                    <span class="text-sm font-medium text-gray-200">{{ $detailTitle }}</span>
                    <button type="button" @click="leftOpen = false"
                            class="w-7 h-7 -mr-1 rounded flex items-center justify-center text-gray-500 hover:text-gray-200 hover:bg-gray-800 transition"
                            aria-label="Fermer le panneau">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </header>
                <div class="flex-1 overflow-y-auto p-4 text-sm text-gray-300">{{ $detail }}</div>
            </aside>
        @endif

        {{-- Contenu principal --}}
        <main class="flex-1 min-w-0 overflow-y-auto">
            @if (session('status'))
                <div class="m-4 px-4 py-2 rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>

        {{-- Panneau latéral DROIT — aide / légende / actions --}}
        @if ($hasHelp)
            <div x-show="rightOpen" x-cloak x-transition.opacity @click="rightOpen = false"
                 class="absolute inset-0 bg-black/50 z-30 lg:hidden"></div>

            <aside x-show="rightOpen" x-cloak
                   x-transition:enter="transition transform ease-out duration-200"
                   x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition transform ease-in duration-150"
                   x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                   class="absolute inset-y-0 right-0 border-l border-gray-800 z-40 lg:relative lg:z-auto
                          w-80 max-w-[85vw] bg-gray-900 shrink-0 flex flex-col shadow-2xl lg:shadow-none">
                <header class="shrink-0 h-11 px-4 flex items-center justify-between border-b border-gray-800">
                    <span class="text-sm font-medium text-gray-200">{{ $helpTitle }}</span>
                    <button type="button" @click="rightOpen = false"
                            class="w-7 h-7 -mr-1 rounded flex items-center justify-center text-gray-500 hover:text-gray-200 hover:bg-gray-800 transition"
                            aria-label="Fermer le panneau">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </header>
                <div class="flex-1 overflow-y-auto p-4 text-sm text-gray-300">{{ $help }}</div>
            </aside>
        @endif

    </div>
</div>

@stack('scripts')
</body>
</html>
