{{--
    Layout du BackOffice — repose désormais sur le shell global <x-app-shell>.
    Le panneau latéral gauche héberge la navigation des sections admin.
    Une page admin peut alimenter le panneau droit (aide/actions) via @section('help').
--}}
@php
    $adminSegment = request()->segment(2) ?: 'dashboard';
    $adminLink = fn (bool $active): string => $active
        ? 'flex items-center gap-3 px-3 py-2 rounded-md bg-sky-500/15 text-sky-300 border border-sky-500/30'
        : 'flex items-center gap-3 px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-gray-100 transition';
    $adminSubLink = fn (bool $active): string => $active
        ? 'flex items-center gap-3 px-3 py-1.5 rounded-md bg-sky-500/10 text-sky-300/80 text-xs'
        : 'flex items-center gap-3 px-3 py-1.5 rounded-md text-gray-500 hover:bg-gray-800 hover:text-gray-300 text-xs transition';
    $sectionLabel = 'mt-4 px-3 text-[10px] uppercase tracking-widest text-gray-600';
@endphp

<x-app-shell
    :title="trim($__env->yieldContent('title', 'BackOffice'))"
    page-title="Administration"
    detail-title="Administration"
    :left-default="auth()->check()">

    <x-slot:detail>
        @auth
            <nav class="flex flex-col gap-0.5 text-sm">
                <a href="{{ route('admin.dashboard') }}"
                   class="{{ $adminLink($adminSegment === 'dashboard' || request()->path() === 'admin') }}">
                    <i class="fa-solid fa-gauge-high w-4 text-center"></i> Dashboard
                </a>

                {{-- ── Contenu ──────────────────────────────────── --}}
                <div class="{{ $sectionLabel }}">Contenu</div>
                <a href="{{ route('admin.articles.index') }}" class="{{ $adminLink($adminSegment === 'articles') }}">
                    <i class="fa-solid fa-newspaper w-4 text-center"></i> Articles / Changelog
                </a>
                <a href="{{ route('admin.wiki.index') }}" class="{{ $adminLink($adminSegment === 'wiki') }}">
                    <i class="fa-solid fa-book-open w-4 text-center"></i> Aide / Wiki
                </a>
                <a href="{{ route('admin.contenu.settings') }}" class="{{ $adminSubLink($adminSegment === 'contenu') }}">
                    <i class="fa-solid fa-gear w-4 text-center"></i> Paramètres
                </a>

                {{-- ── Modèles Météo ────────────────────────────── --}}
                <div class="{{ $sectionLabel }}">Modèles Météo</div>
                <a href="{{ route('admin.apis.index') }}" class="{{ $adminLink($adminSegment === 'apis') }}">
                    <i class="fa-solid fa-plug w-4 text-center"></i> APIs météo
                </a>
                <a href="{{ route('admin.models.index') }}" class="{{ $adminLink($adminSegment === 'models') }}">
                    <i class="fa-solid fa-cloud w-4 text-center"></i> Modèles météo
                </a>
                <a href="{{ route('admin.reliability.compare') }}" class="{{ $adminLink($adminSegment === 'reliability') }}">
                    <i class="fa-solid fa-flask-vial w-4 text-center"></i> Fiabilité des modèles
                </a>
                <a href="{{ route('admin.meteo.settings') }}" class="{{ $adminSubLink($adminSegment === 'meteo') }}">
                    <i class="fa-solid fa-gear w-4 text-center"></i> Paramètres
                </a>

                {{-- ── Sites ────────────────────────────────────── --}}
                <div class="{{ $sectionLabel }}">Sites</div>
                <a href="{{ route('admin.sites.index') }}" class="{{ $adminLink($adminSegment === 'sites' && request()->segment(3) !== 'settings') }}">
                    <i class="fa-solid fa-mountain-sun w-4 text-center"></i> Sites
                </a>
                <a href="{{ route('admin.data-quality.index') }}" class="{{ $adminLink($adminSegment === 'data-quality') }}">
                    <i class="fa-solid fa-code-merge w-4 text-center"></i> Fusion / dédoublonnage
                </a>
                <a href="{{ route('admin.sites.settings') }}" class="{{ $adminSubLink($adminSegment === 'sites' && request()->segment(3) === 'settings') }}">
                    <i class="fa-solid fa-gear w-4 text-center"></i> Paramètres
                </a>

                {{-- ── Balises ──────────────────────────────────── --}}
                <div class="{{ $sectionLabel }}">Balises</div>
                <a href="{{ route('admin.balises.index') }}" class="{{ $adminLink($adminSegment === 'balises' && request()->segment(3) !== 'settings') }}">
                    <i class="fa-solid fa-tower-broadcast w-4 text-center"></i> Balises
                </a>
                <a href="{{ route('admin.balises.settings') }}" class="{{ $adminSubLink($adminSegment === 'balises' && request()->segment(3) === 'settings') }}">
                    <i class="fa-solid fa-gear w-4 text-center"></i> Paramètres
                </a>

                {{-- ── Stations météo ─────────────────────────── --}}
                <div class="{{ $sectionLabel }}">Stations météo</div>
                <a href="{{ route('admin.weather-stations.index') }}" class="{{ $adminLink($adminSegment === 'weather-stations' && request()->segment(3) !== 'settings') }}">
                    <i class="fa-solid fa-tower-broadcast w-4 text-center" style="color:#3b82f6"></i> Stations
                </a>
                <a href="{{ route('admin.station-apis.index') }}" class="{{ $adminLink($adminSegment === 'station-apis') }}">
                    <i class="fa-solid fa-plug w-4 text-center" style="color:#3b82f6"></i> APIs stations
                </a>
                <a href="{{ route('admin.weather-stations.settings') }}" class="{{ $adminSubLink($adminSegment === 'weather-stations' && request()->segment(3) === 'settings') }}">
                    <i class="fa-solid fa-gear w-4 text-center"></i> Paramètres
                </a>

                {{-- ── Système ──────────────────────────────────── --}}
                <div class="{{ $sectionLabel }}">Système</div>
                <a href="{{ route('admin.users.index') }}" class="{{ $adminLink($adminSegment === 'users') }}">
                    <i class="fa-solid fa-users w-4 text-center"></i> Utilisateurs
                </a>
                <a href="{{ route('admin.traffic.index') }}" class="{{ $adminLink($adminSegment === 'traffic') }}">
                    <i class="fa-solid fa-chart-line w-4 text-center"></i> Trafic
                </a>
                <a href="{{ route('admin.logs.index') }}" class="{{ $adminLink($adminSegment === 'logs') }}">
                    <i class="fa-solid fa-scroll w-4 text-center"></i> Logs / monitoring
                </a>
                <a href="{{ route('admin.settings.index') }}" class="{{ $adminSubLink($adminSegment === 'settings' && request()->segment(3) !== 'audit') }}">
                    <i class="fa-solid fa-gear w-4 text-center"></i> Paramètres
                </a>
                <a href="{{ route('admin.settings.audit') }}" class="{{ $adminSubLink($adminSegment === 'settings' && request()->segment(3) === 'audit') }}">
                    <i class="fa-solid fa-clock-rotate-left w-4 text-center"></i> Historique
                </a>

                {{-- ── Divers ───────────────────────────────────── --}}
                <div class="{{ $sectionLabel }}">Divers</div>
                <a href="{{ route('admin.modules.index') }}" class="{{ $adminLink($adminSegment === 'modules') }}">
                    <i class="fa-solid fa-puzzle-piece w-4 text-center"></i> Modules
                </a>
            </nav>
        @endauth
    </x-slot:detail>

    <x-slot:help>@yield('help')</x-slot:help>

    <div class="p-4 sm:p-6">
        @if (session('status'))
            <x-admin.alert type="success">{{ session('status') }}</x-admin.alert>
        @endif
        @if (session('error'))
            <x-admin.alert type="error">{{ session('error') }}</x-admin.alert>
        @endif
        @if (session('warning'))
            <x-admin.alert type="warning">{{ session('warning') }}</x-admin.alert>
        @endif

        @yield('content')
    </div>

</x-app-shell>
