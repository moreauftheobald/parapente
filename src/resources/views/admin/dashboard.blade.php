@extends('layouts.admin')
@section('title', 'Dashboard')

@section('content')
    <div class="max-w-5xl">
        <x-admin.page-title title="Dashboard" :subtitle="'Bienvenue ' . auth()->user()->name . '.'" />

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Sites de vol</div>
                <div class="text-3xl font-mono text-white">{{ number_format($sitesTotal, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-1">
                    <span class="text-emerald-400 font-medium">{{ $sitesActive }}</span> actif{{ $sitesActive > 1 ? 's' : '' }}
                </div>
            </div>

            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Balises météo</div>
                <div class="text-3xl font-mono text-white">{{ number_format($balisesTotal, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-1">
                    <span class="text-emerald-400 font-medium">{{ $balisesActive }}</span> active{{ $balisesActive > 1 ? 's' : '' }}
                </div>
            </div>

            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
                <div class="text-xs uppercase tracking-wider text-gray-500 mb-1">Utilisateurs</div>
                <div class="text-3xl font-mono text-white">{{ number_format($usersTotal, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-1">total</div>
            </div>
        </div>

        @if (! empty($sitesByOrigin))
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
                <h2 class="text-xs uppercase tracking-wider text-gray-500 mb-3">Sites par origine</h2>
                <ul class="text-sm divide-y divide-gray-800">
                    @foreach ($sitesByOrigin as $source => $n)
                        <li class="flex justify-between py-2">
                            <span class="text-gray-300">{{ $source }}</span>
                            <span class="font-mono text-gray-400">{{ number_format($n, 0, ',', ' ') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-gray-900/50 border border-gray-800 border-dashed rounded-xl p-5">
            <p class="text-sm text-gray-500">
                <strong class="text-gray-400">Sections à venir</strong> : gestion des sites
                (activation, édition des conditions), utilisateurs, balises, modèles météo,
                logs, paramètres globaux.
            </p>
        </div>
    </div>
@endsection
