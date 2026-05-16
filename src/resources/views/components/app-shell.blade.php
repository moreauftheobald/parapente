@props([
    'title'        => null,   // titre de l'onglet navigateur
    'pageTitle'    => null,   // libellé affiché dans la barre du haut
    'detailTitle'  => 'Détail',
    'helpTitle'    => 'Aide',
    'leftDefault'  => false,  // panneau gauche ouvert d'emblée (desktop only)
    'rightDefault' => false,  // panneau droit ouvert d'emblée (desktop only)
    // Permet à une page (typiquement la carte) de fournir son propre scope
    // Alpine racine. Doit exposer au minimum `leftOpen` et `rightOpen`.
    // Quand fourni, leftDefault/rightDefault sont ignorés.
    'xData'        => null,
    // Classes Tailwind additionnelles pour les volets — la carte les
    // élargit en desktop large pour ses graphes / tableaux.
    'leftClass'    => 'w-80 max-w-[85vw]',
    'rightClass'   => 'w-80 max-w-[85vw]',
    // Classes additionnelles sur le <main> (utile pour retirer
    // overflow-y-auto sur la vue carte qui gère son propre layout).
    'mainClass'    => 'overflow-y-auto',
    // Classes additionnelles sur le wrapper racine (utile quand le
    // scope fourni via xData n'expose pas .leftOpen / .rightOpen
    // directement, etc.).
    'rootClass'    => '',
    // Classes sur le conteneur interne des slots latéraux. La carte
    // les passe à `flex-1 min-h-0` (sans padding) car son contenu
    // (lp-tabpane / rp-pane) gère son propre layout.
    'detailBodyClass' => 'flex-1 overflow-y-auto p-4 text-sm text-gray-300',
    'helpBodyClass'   => 'flex-1 overflow-y-auto p-4 text-sm text-gray-300',
    // Cache les en-têtes par défaut des volets (titre + bouton close
    // dans une barre h-11). La carte fournit ses propres titres dans
    // le contenu et préfère pas d'en-tête.
    'hideDetailHeader' => false,
    'hideHelpHeader'   => false,
])

@php
    // Un slot conditionnel (@auth / @hasSection) est extrait à la compilation
    // mais peut être vide à l'exécution : on teste le contenu réel.
    $hasDetail = isset($detail) && trim((string) $detail) !== '';
    $hasHelp   = isset($help)   && trim((string) $help)   !== '';
    $leftInit  = $hasDetail && $leftDefault  ? "window.matchMedia('(min-width: 1024px)').matches" : 'false';
    $rightInit = $hasHelp   && $rightDefault ? "window.matchMedia('(min-width: 1024px)').matches" : 'false';
    $xDataExpr = $xData ?? "{ leftOpen: {$leftInit}, rightOpen: {$rightInit} }";
@endphp

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title . ' · ' : '' }}Qui Vole ?</title>

    {{-- ── Favicon (silhouette parapente, fond sky-500) ───────────────── --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/x-icon" sizes="any" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#111827">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Anti-FOUC + correctifs mobile injectés AVANT l'arrivée du bundle CSS
         Vite. Plusieurs problèmes spécifiques mobile traités ici :
         - [x-cloak] : masque les éléments Alpine x-show pendant le boot
         - html/body en dvh : évite que la zone soit recalculée depuis
           un parent non-mesuré, surtout sur Safari iOS / Chrome Android
           où 100% du parent peut donner une hauteur instable
         - will-change sur les <aside> : pré-réserve une couche GPU
           pour les transitions transform, supprime les artefacts de
           compositing (volet « fantôme » semi-transparent qui freeze)
         - background-color sur html : évite qu'une bande blanche
           apparaisse en bordure quand la barre URL navigateur se
           rétracte en portrait. --}}
    <style>
        [x-cloak] { display: none !important; }
        /* Reset minimal html/body : aucune hauteur imposée — le shell
           prend en charge l'ancrage via position:fixed. Cela évite
           toute dépendance à une chaîne `height:100% / 100dvh` qui
           est instable sur Chrome Android et Safari iOS (navbar du
           navigateur qui apparait/disparait, safe areas, etc.). */
        html, body { background-color: #030712; margin: 0; min-height: 100dvh; min-height: 100vh; }
        /* Shell racine : position:fixed pour s'ancrer SUR la viewport
           visuelle, indépendamment de toute hauteur de parent. Cela
           résout les glitches mobiles signalés (navbar qui disparait,
           volet qui dépasse au-dessus, bande grise en haut). */
        .app-shell-root { position: fixed; top: 0; right: 0; bottom: 0; left: 0; display: flex; flex-direction: column; overflow: hidden; }
        /* will-change sur les volets : pré-réserve une couche GPU pour
           les transitions transform, supprime les artefacts de
           compositing (volet « fantôme » semi-transparent qui freeze)
           sur Safari iOS / Chrome Android. */
        aside { will-change: transform; }
    </style>

    {{-- Font Awesome auto-hébergé via Vite (cf. resources/css/app.css) :
         pas de dépendance CDN cloudflare qui était bloquée par
         certains opérateurs mobiles / DNS publics. --}}

    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100">

<div class="app-shell-root {{ $rootClass }}"
     x-data="{{ $xDataExpr }}"
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
                          {{ $leftClass }} bg-gray-900 shrink-0 flex flex-col shadow-2xl lg:shadow-none">
                @unless ($hideDetailHeader)
                    <header class="shrink-0 h-11 px-4 flex items-center justify-between border-b border-gray-800">
                        <span class="text-sm font-medium text-gray-200">{{ $detailTitle }}</span>
                        <button type="button" @click="leftOpen = false"
                                class="w-7 h-7 -mr-1 rounded flex items-center justify-center text-gray-500 hover:text-gray-200 hover:bg-gray-800 transition"
                                aria-label="Fermer le panneau">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </header>
                @endunless
                <div class="{{ $detailBodyClass }}">{{ $detail }}</div>
            </aside>
        @endif

        {{-- Contenu principal --}}
        <main class="flex-1 min-w-0 {{ $mainClass }}">
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
                          {{ $rightClass }} bg-gray-900 shrink-0 flex flex-col shadow-2xl lg:shadow-none">
                @unless ($hideHelpHeader)
                    <header class="shrink-0 h-11 px-4 flex items-center justify-between border-b border-gray-800">
                        <span class="text-sm font-medium text-gray-200">{{ $helpTitle }}</span>
                        <button type="button" @click="rightOpen = false"
                                class="w-7 h-7 -mr-1 rounded flex items-center justify-center text-gray-500 hover:text-gray-200 hover:bg-gray-800 transition"
                                aria-label="Fermer le panneau">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </header>
                @endunless
                <div class="{{ $helpBodyClass }}">{{ $help }}</div>
            </aside>
        @endif

    </div>
</div>

@stack('scripts')
</body>
</html>
