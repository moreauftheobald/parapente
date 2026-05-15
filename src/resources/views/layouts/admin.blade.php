{{--
    Layout du BackOffice — repose désormais sur le shell global <x-app-shell>.
    Le panneau latéral gauche héberge la navigation des sections admin.
    Une page admin peut alimenter le panneau droit (aide/actions) via @section('help').
--}}
@php
    $adminSection = request()->segment(2) ?: 'dashboard';
    $adminLink = fn (bool $active): string => $active
        ? 'flex items-center gap-3 px-3 py-2 rounded-md bg-sky-500/15 text-sky-300 border border-sky-500/30'
        : 'flex items-center gap-3 px-3 py-2 rounded-md text-gray-400 hover:bg-gray-800 hover:text-gray-100 transition';
@endphp

<x-app-shell
    :title="trim($__env->yieldContent('title', 'BackOffice'))"
    page-title="Administration"
    detail-title="Administration"
    :left-default="auth()->check()">

    <x-slot:detail>
        @auth
            <nav class="flex flex-col gap-1 text-sm">
                <a href="{{ route('admin.dashboard') }}"
                   class="{{ $adminLink($adminSection === 'dashboard' || request()->path() === 'admin') }}">
                    <i class="fa-solid fa-gauge-high w-4 text-center"></i> Dashboard
                </a>

                <div class="mt-3 px-3 text-[10px] uppercase tracking-widest text-gray-600">Contenu</div>
                <a href="{{ route('admin.articles.index') }}" class="{{ $adminLink($adminSection === 'articles') }}">
                    <i class="fa-solid fa-newspaper w-4 text-center"></i> Articles / Changelog
                </a>

                <div class="mt-3 px-3 text-[10px] uppercase tracking-widest text-gray-600">Données</div>
                <a href="{{ route('admin.sites.index') }}" class="{{ $adminLink($adminSection === 'sites') }}">
                    <i class="fa-solid fa-mountain-sun w-4 text-center"></i> Sites
                </a>
                <a href="{{ route('admin.balises.index') }}" class="{{ $adminLink($adminSection === 'balises') }}">
                    <i class="fa-solid fa-tower-broadcast w-4 text-center"></i> Balises
                </a>
                <a href="{{ route('admin.models.index') }}" class="{{ $adminLink($adminSection === 'models') }}">
                    <i class="fa-solid fa-cloud w-4 text-center"></i> Modèles météo
                </a>
                <a href="{{ route('admin.apis.index') }}" class="{{ $adminLink($adminSection === 'apis') }}">
                    <i class="fa-solid fa-plug w-4 text-center"></i> APIs météo
                </a>
                <a href="{{ route('admin.sync.index') }}" class="{{ $adminLink($adminSection === 'sync') }}">
                    <i class="fa-solid fa-rotate w-4 text-center"></i> Synchronisation
                </a>
                <a href="{{ route('admin.data-quality.index') }}" class="{{ $adminLink($adminSection === 'data-quality') }}">
                    <i class="fa-solid fa-clipboard-check w-4 text-center"></i> Qualité des données
                </a>

                <div class="mt-3 px-3 text-[10px] uppercase tracking-widest text-gray-600">Système</div>
                <a href="{{ route('admin.modules.index') }}" class="{{ $adminLink($adminSection === 'modules') }}">
                    <i class="fa-solid fa-puzzle-piece w-4 text-center"></i> Modules
                </a>
                <a href="{{ route('admin.users.index') }}" class="{{ $adminLink($adminSection === 'users') }}">
                    <i class="fa-solid fa-users w-4 text-center"></i> Utilisateurs
                </a>
                <a href="{{ route('admin.data-coverage.index') }}" class="{{ $adminLink($adminSection === 'data-coverage') }}">
                    <i class="fa-solid fa-chart-area w-4 text-center"></i> Couverture des données
                </a>
                <a href="{{ route('admin.logs.index') }}" class="{{ $adminLink($adminSection === 'logs') }}">
                    <i class="fa-solid fa-scroll w-4 text-center"></i> Logs / monitoring
                </a>
                <a href="{{ route('admin.settings.index') }}" class="{{ $adminLink($adminSection === 'settings') }}">
                    <i class="fa-solid fa-gear w-4 text-center"></i> Paramètres
                </a>
            </nav>
        @endauth
    </x-slot:detail>

    <x-slot:help>@yield('help')</x-slot:help>

    <div class="p-4 sm:p-6">
        @yield('content')
    </div>

</x-app-shell>
