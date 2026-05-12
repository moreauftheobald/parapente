<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Qui Vole ?')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Font Awesome 6 (icônes de la barre de menu globale) --}}
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
          crossorigin="anonymous" referrerpolicy="no-referrer">

    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100 h-screen flex flex-col overflow-hidden">

{{-- Barre de menu globale (commune à tous les écrans de l'application) --}}
@include('partials.app-shell-navbar', ['pageTitle' => 'Carte météo'])

{{-- Contenu principal --}}
<main class="flex-1 overflow-hidden">
    @yield('content')
</main>

@stack('scripts')
</body>
</html>
