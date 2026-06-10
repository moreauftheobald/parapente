@extends('layouts.admin')
@section('title', 'Paramètres')

@section('content')
<div class="max-w-6xl">
    <x-admin.page-title title="Paramètres">
        <x-slot:subtitle>
            Tous les paramètres de l'application, classés par catégorie — survoler le « ? » d'un champ
            pour comprendre sur quoi il agit.
            <a href="{{ route('admin.settings.audit') }}" class="text-sky-400 hover:underline ml-1">
                <i class="fa-solid fa-clock-rotate-left"></i> Historique des modifications
            </a>
        </x-slot:subtitle>
    </x-admin.page-title>

    {{-- Onglets par catégorie --}}
    <div class="flex gap-1 mb-5 border-b border-gray-800 flex-wrap">
        @foreach ($categories as $key => $cat)
            @if ($key === $tab)
                <span class="px-4 py-2 text-sm text-sky-300 border-b-2 border-sky-500 -mb-px whitespace-nowrap">
                    <i class="fa-solid {{ $cat['icon'] }} mr-1"></i> {{ $cat['label'] }}
                </span>
            @else
                <a href="{{ route('admin.settings.index', ['tab' => $key]) }}"
                   class="px-4 py-2 text-sm text-gray-400 hover:text-gray-200 whitespace-nowrap">
                    <i class="fa-solid {{ $cat['icon'] }} mr-1"></i> {{ $cat['label'] }}
                </a>
            @endif
        @endforeach
    </div>

    @if (! empty($categories[$tab]['note']))
        <x-admin.alert type="info">{{ $categories[$tab]['note'] }}</x-admin.alert>
    @endif

    @include('admin.settings._groups-form', [
        'settingsGroups' => $groups,
        'saveAction'     => route('admin.settings.update', ['tab' => $tab]),
    ])
</div>
@endsection
