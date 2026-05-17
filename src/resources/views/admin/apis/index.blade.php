@extends('layouts.admin')
@section('title', 'APIs météo')

@php
    $authBadge = function (string $type): string {
        return match ($type) {
            'oauth2'     => '<span class="px-2 py-0.5 text-[10px] font-mono uppercase rounded bg-violet-500/15 border border-violet-500/30 text-violet-300">OAuth2</span>',
            'api_key'    => '<span class="px-2 py-0.5 text-[10px] font-mono uppercase rounded bg-amber-500/15 border border-amber-500/30 text-amber-300">API key</span>',
            'user_agent' => '<span class="px-2 py-0.5 text-[10px] font-mono uppercase rounded bg-sky-500/15 border border-sky-500/30 text-sky-300">UA</span>',
            default      => '<span class="px-2 py-0.5 text-[10px] font-mono uppercase rounded bg-gray-700/60 border border-gray-600 text-gray-400">none</span>',
        };
    };
@endphp

@section('content')
<div class="max-w-7xl">
    <x-admin.page-title title="APIs météo"
        subtitle="Sources de données pour le fetch des modèles. Chaque modèle pointe vers UNE API (politique single-shot)." />

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left">API</th>
                    <th class="px-4 py-3 text-left w-32">Auth</th>
                    <th class="px-4 py-3 text-left">URL</th>
                    <th class="px-4 py-3 text-right w-32">Quota</th>
                    <th class="px-4 py-3 text-right w-32">Aujourd'hui</th>
                    <th class="px-4 py-3 text-left w-44">Dernier appel</th>
                    <th class="px-4 py-3 text-center w-20">Statut</th>
                    <th class="px-4 py-3 text-right w-24">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($apis as $api)
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <div class="text-gray-100 font-medium">{{ $api->name }}</div>
                            <div class="text-xs text-gray-500 font-mono">{{ $api->code }}</div>
                        </td>
                        <td class="px-4 py-3">{!! $authBadge($api->auth_type) !!}</td>
                        <td class="px-4 py-3 text-xs font-mono text-gray-500 truncate max-w-xs" title="{{ $api->base_url }}">
                            {{ $api->base_url }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-xs text-gray-400">
                            {{ $api->daily_quota !== null ? number_format($api->daily_quota, 0, ',', ' ') . '/j' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-right font-mono text-xs">
                            @php
                                $today      = now()->toDateString();
                                $isToday    = $api->requests_counter_date && $api->requests_counter_date->toDateString() === $today;
                                $count      = $isToday ? $api->requests_today : 0;
                                $pct        = ($api->daily_quota && $count) ? min(100, round($count / $api->daily_quota * 100)) : null;
                                $colorClass = $pct === null ? 'text-gray-400'
                                    : ($pct < 50 ? 'text-emerald-300' : ($pct < 80 ? 'text-amber-300' : 'text-red-300'));
                            @endphp
                            <span class="{{ $colorClass }}">{{ $count }}</span>
                            @if ($pct !== null)
                                <span class="text-gray-600 ml-1">({{ $pct }}%)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if ($api->last_error_at && (! $api->last_success_at || $api->last_error_at->gt($api->last_success_at)))
                                <span class="text-red-400" title="{{ $api->last_error }}">
                                    <i class="fa-solid fa-circle-exclamation"></i> {{ $api->last_error_at->diffForHumans() }}
                                </span>
                            @elseif ($api->last_success_at)
                                <span class="text-emerald-400">
                                    <i class="fa-solid fa-circle-check"></i> {{ $api->last_success_at->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-gray-600">jamais</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($api->active)
                                <i class="fa-solid fa-circle-check text-emerald-400 text-xl" title="Active"></i>
                            @else
                                <i class="fa-solid fa-circle-xmark text-red-400 text-xl" title="Inactive"></i>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <a href="{{ route('admin.apis.edit', $api) }}"
                                   class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white transition"
                                   title="Éditer">
                                    <i class="fa-solid fa-pen text-xs"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.apis.toggle', $api) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded border transition
                                                @class([
                                                    'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $api->active,
                                                    'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $api->active,
                                                ])"
                                            title="{{ $api->active ? 'Désactiver' : 'Activer' }}">
                                        <i class="fa-solid fa-power-off text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-0 py-0">
                            <x-admin.empty-state icon="fa-solid fa-plug" message="Aucune API configurée." class="border-0 rounded-none" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-500 mt-4">
        <i class="fa-solid fa-circle-info"></i>
        Les APIs sont reconnues par leur <code class="font-mono text-gray-400">code</code> (mappé en dur dans
        <code class="font-mono text-gray-400">WeatherApiRegistry</code>). Pour ajouter une nouvelle source, créer une classe
        implémentant <code class="font-mono text-gray-400">WeatherApiInterface</code> et l'enregistrer dans le registry.
    </p>
</div>
@endsection
