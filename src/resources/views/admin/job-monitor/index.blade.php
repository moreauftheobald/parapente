@extends('layouts.admin')
@section('title', $config['title'])

@php
    $badgeStatus = fn (string $status): string => match ($status) {
        'success' => 'success',
        'failed'  => 'danger',
        'running' => 'warning',
        default   => 'neutral',
    };
    $badgeLabel = fn (string $status): string => match ($status) {
        'success' => 'OK',
        'failed'  => 'Échec',
        'running' => 'En cours',
        default   => $status,
    };
    $timeAgo = function (?\Carbon\Carbon $dt): string {
        if (! $dt) return '—';
        $diff = $dt->diffInMinutes(now());
        if ($diff < 1)   return 'à l\'instant';
        if ($diff < 60)  return $diff . ' min';
        if ($diff < 1440) return (int) floor($diff / 60) . 'h ' . ($diff % 60) . 'min';
        return $dt->diffForHumans();
    };
@endphp

@section('content')
<div class="max-w-7xl">
    <x-admin.page-title :title="$config['title']">
        <x-slot:subtitle>{{ $config['subtitle'] }}</x-slot:subtitle>
    </x-admin.page-title>

    {{-- ─── Résumé par job ────────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
        @foreach ($summary as $class => $s)
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="font-medium text-sm text-gray-200">{{ $s['label'] }}</div>
                    @if ($s['last'])
                        <x-admin.badge :status="$badgeStatus($s['last']->status)">
                            {{ $badgeLabel($s['last']->status) }}
                        </x-admin.badge>
                    @else
                        <x-admin.badge status="neutral">Jamais exécuté</x-admin.badge>
                    @endif
                </div>

                <div class="text-xs text-gray-500 mb-3">
                    <i class="fa-solid fa-clock w-3"></i> {{ $s['schedule'] }}
                </div>

                @if ($s['last'])
                    <div class="text-xs text-gray-400 mb-1">
                        <span class="text-gray-500">Dernière :</span>
                        {{ $s['last']->started_at->format('H:i:s') }}
                        <span class="text-gray-600">({{ $timeAgo($s['last']->started_at) }})</span>
                        @if ($s['last']->duration_ms)
                            <span class="text-gray-600">· {{ $s['last']->durationFormatted() }}</span>
                        @endif
                    </div>
                    @if ($s['last']->message)
                        <div class="text-xs font-mono text-gray-400 truncate" title="{{ $s['last']->message }}">
                            {{ $s['last']->message }}
                        </div>
                    @endif
                    @if ($s['last']->status === 'failed' && $s['last']->error_message)
                        <div class="text-xs font-mono text-red-400 truncate mt-1" title="{{ $s['last']->error_message }}">
                            {{ Str::limit($s['last']->error_message, 120) }}
                        </div>
                    @endif
                @endif

                @if ($s['total_24h'] > 0)
                    <div class="flex items-center gap-3 mt-3 pt-3 border-t border-gray-800 text-xs">
                        <span class="text-gray-500">24h :</span>
                        <span class="text-gray-300">{{ $s['total_24h'] }} runs</span>
                        @if ($s['success_24h'] > 0)
                            <span class="text-emerald-400">{{ $s['success_24h'] }} <i class="fa-solid fa-check"></i></span>
                        @endif
                        @if ($s['failed_24h'] > 0)
                            <span class="text-red-400">{{ $s['failed_24h'] }} <i class="fa-solid fa-xmark"></i></span>
                        @endif
                        <span class="text-gray-600">moy. {{ $s['avg_duration'] }}</span>
                    </div>
                @endif

                <div class="mt-2">
                    <a href="{{ request()->fullUrlWithQuery(['job' => $class]) }}"
                       class="text-xs text-sky-400 hover:text-sky-300 transition">
                        Voir l'historique →
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ─── Filtres ───────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-4">
        <form method="GET" class="flex items-center gap-2 flex-wrap">
            <select name="job" onchange="this.form.submit()"
                    class="px-3 py-1.5 bg-gray-950 border border-gray-700 rounded text-xs text-gray-100 focus:outline-none focus:border-sky-500 cursor-pointer">
                <option value="">Tous les jobs</option>
                @foreach ($summary as $class => $s)
                    <option value="{{ $class }}" @selected($jobFilter === $class)>
                        {{ $s['label'] }}
                    </option>
                @endforeach
            </select>
            <select name="status" onchange="this.form.submit()"
                    class="px-3 py-1.5 bg-gray-950 border border-gray-700 rounded text-xs text-gray-100 focus:outline-none focus:border-sky-500 cursor-pointer">
                <option value="">Tous statuts</option>
                <option value="success" @selected($status === 'success')>Succès</option>
                <option value="failed" @selected($status === 'failed')>Échecs</option>
                <option value="running" @selected($status === 'running')>En cours</option>
            </select>
            @if ($jobFilter || $status)
                <a href="{{ route("admin.monitor.{$group}") }}" class="text-xs text-gray-400 hover:text-white transition">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </a>
            @endif
        </form>
    </div>

    {{-- ─── Tableau des exécutions ─────────────────────────────── --}}
    @if ($entries->isEmpty())
        <x-admin.empty-state icon="fa-solid fa-clipboard-list" message="Aucune exécution enregistrée pour cette section." />
    @else
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                    <tr>
                        <th class="px-4 py-2.5 text-left w-8">Statut</th>
                        <th class="px-4 py-2.5 text-left">Job</th>
                        <th class="px-4 py-2.5 text-left w-40">Démarré</th>
                        <th class="px-4 py-2.5 text-left w-24">Durée</th>
                        <th class="px-4 py-2.5 text-left">Résultat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800" x-data="{ expanded: null }">
                    @foreach ($entries as $entry)
                        <tr class="hover:bg-gray-800/40 cursor-pointer transition"
                            @click="expanded = expanded === {{ $entry->id }} ? null : {{ $entry->id }}">
                            <td class="px-4 py-2.5">
                                @if ($entry->status === 'success')
                                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 inline-block" title="Succès"></span>
                                @elseif ($entry->status === 'failed')
                                    <span class="w-2.5 h-2.5 rounded-full bg-red-400 inline-block" title="Échec"></span>
                                @else
                                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400 animate-pulse inline-block" title="En cours"></span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-300">
                                {{ class_basename($entry->job_class) }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-400">
                                {{ $entry->started_at->format('d/m H:i:s') }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-400">
                                {{ $entry->durationFormatted() }}
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                @if ($entry->status === 'failed')
                                    <span class="text-red-400 font-mono">{{ Str::limit($entry->error_message, 100) }}</span>
                                @elseif ($entry->message)
                                    <span class="text-gray-400">{{ Str::limit($entry->message, 100) }}</span>
                                @else
                                    <span class="text-gray-600">—</span>
                                @endif
                            </td>
                        </tr>
                        {{-- Détail expansible --}}
                        <tr x-show="expanded === {{ $entry->id }}" x-cloak
                            class="bg-gray-950/50">
                            <td colspan="5" class="px-6 py-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                                    <div>
                                        <div class="text-gray-500 uppercase tracking-wider mb-1">Classe complète</div>
                                        <div class="font-mono text-gray-300">{{ $entry->job_class }}</div>
                                    </div>
                                    <div>
                                        <div class="text-gray-500 uppercase tracking-wider mb-1">Timestamps</div>
                                        <div class="text-gray-400">
                                            Démarré : {{ $entry->started_at->format('Y-m-d H:i:s') }}<br>
                                            @if ($entry->finished_at)
                                                Terminé : {{ $entry->finished_at->format('Y-m-d H:i:s') }}
                                            @endif
                                        </div>
                                    </div>
                                    @if ($entry->message)
                                        <div class="md:col-span-2">
                                            <div class="text-gray-500 uppercase tracking-wider mb-1">Message</div>
                                            <div class="font-mono text-gray-300">{{ $entry->message }}</div>
                                        </div>
                                    @endif
                                    @if ($entry->metadata)
                                        <div class="md:col-span-2">
                                            <div class="text-gray-500 uppercase tracking-wider mb-1">Métadonnées</div>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($entry->metadata as $key => $val)
                                                    <span class="px-2 py-0.5 bg-gray-800 border border-gray-700 rounded font-mono text-gray-300">
                                                        {{ $key }}: <span class="text-sky-300">{{ is_array($val) ? json_encode($val) : $val }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if ($entry->error_message)
                                        <div class="md:col-span-2">
                                            <div class="text-gray-500 uppercase tracking-wider mb-1">Erreur</div>
                                            <div class="font-mono text-red-400 whitespace-pre-wrap">{{ $entry->error_message }}</div>
                                        </div>
                                    @endif
                                    @if ($entry->error_trace)
                                        <div class="md:col-span-2">
                                            <div class="text-gray-500 uppercase tracking-wider mb-1">Stack trace</div>
                                            <pre class="font-mono text-gray-500 text-[10px] whitespace-pre-wrap max-h-40 overflow-y-auto bg-gray-900 p-2 rounded">{{ Str::limit($entry->error_trace, 2000) }}</pre>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $entries->links() }}
        </div>
    @endif
</div>
@endsection
