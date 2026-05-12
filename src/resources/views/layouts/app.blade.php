<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Qui Vole ?')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100 h-screen flex flex-col overflow-hidden">

{{-- Navbar --}}
<nav class="bg-gray-900 border-b border-gray-800 px-4 py-2 flex items-center gap-6 shrink-0 z-50">
    <span class="font-bold text-sky-400 text-lg tracking-tight">⛶ Qui Vole ?</span>
    <a href="{{ route('map') }}"
       class="text-sm text-gray-300 hover:text-white transition {{ request()->routeIs('map') ? 'text-white font-medium' : '' }}">
        Carte météo
    </a>
    {{-- Futurs liens : Journal de vol, Comparatif --}}
</nav>

{{-- Contenu principal --}}
<main class="flex-1 overflow-hidden">
    @yield('content')
</main>

</body>
</html>
