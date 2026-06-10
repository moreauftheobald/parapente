@extends('layouts.admin')
@section('title', 'Logs — Jobs')

@php
    $statusBadge = fn (string $s) => match ($s) {
        'success' => 'success',
        'running' => 'info',
        'failed'  => 'danger',
        default   => 'neutral',
    };
    $statusLabel = fn (string $s) => match ($s) {
        'success' => 'Succès',
        'running' => 'En cours',
        'failed'  => 'Échec',
        default   => $s,
    };
    $groupLabel = fn (?string $g) => \App\Http\Controllers\Admin\LogController::GROUP_LABELS[$g] ?? ucfirst((string) $g);
    $jobLabel   = fn (string $class) => $labels[$class] ?? class_basename($class);
@endphp

@section('content')
<div class="max-w-6xl">
    <x-admin.page-title title="Logs — exécutions des jobs"
        subtitle="Historique 30 jours de chaque job (et des runs du sidecar), filtrable par catégorie." />

    {{-- Onglets --}}
    @include('admin.logs._tabs', ['active' => 'jobs'])

    {{-- Filtres --}}
    <form method="GET" class="flex items-center gap-2 flex-wrap mb-4">
        <select name="group" onchange="this.form.submit()"
                class="px-3 py-1.5 bg-gray-950 border border-gray-700 rounded text-xs text-gray-100 focus:outline-none focus:border-sky-500 cursor-pointer">
            <option value="">Toutes catégories</option>
            @foreach ($groups as $g)
                <option value="{{ $g }}" @selected($group === $g)>{{ $groupLabel($g) }}</option>
            @endforeach
        </select>
        <select name="job" onchange="this.form.submit()"
                class="px-3 py-1.5 bg-gray-950 border border-gray-700 rounded text-xs text-gray-100 focus:outline-none focus:border-sky-500 cursor-pointer max-w-[22rem]">
            <option value="">Tous les jobs</option>
            @foreach ($classes as $c)
                <option value="{{ $c }}" @selected($job === $c)>{{ $jobLabel($c) }}</option>
            @endforeach
        </select>
        <select name="status" onchange="this.form.submit()"
                class="px-3 py-1.5 bg-gray-950 border border-gray-700 rounded text-xs text-gray-100 focus:outline-none focus:border-sky-500 cursor-pointer">
            <option value="">Tous statuts</option>
            @foreach (['success' => 'Succès', 'failed' => 'Échec', 'running' => 'En cours'] as $val => $lbl)
                <option value="{{ $val }}" @selected($status === $val)>{{ $lbl }}</option>
            @endforeach
        </select>
        @if ($group || $job || $status)
            <a href="{{ route('admin.logs.jobs') }}" class="text-xs text-gray-400 hover:text-white transition" title="Réinitialiser les filtres">
                <i class="fa-solid fa-rotate-left"></i>
            </a>
        @endif
        <span class="text-xs text-gray-500 ml-auto">{{ number_format($runs->total(), 0, ',', ' ') }} exécution(s)</span>
    </form>

    @if ($runs->isEmpty())
        <x-admin.empty-state icon="fa-solid fa-clipboard-list" message="Aucune exécution pour ces filtres." />
    @else
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                    <tr>
                        <th class="px-4 py-2 text-left w-40">Début</th>
                        <th class="px-4 py-2 text-left">Job</th>
                        <th class="px-4 py-2 text-left">Catégorie</th>
                        <th class="px-4 py-2 text-left">Statut</th>
                        <th class="px-4 py-2 text-left w-24">Durée</th>
                        <th class="px-4 py-2 text-left">Résultat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach ($runs as $run)
                        <tr class="{{ $run->status === 'failed' ? 'bg-red-500/5' : '' }} hover:bg-gray-800/40 align-top">
                            <td class="px-4 py-2 font-mono text-xs text-gray-400 whitespace-nowrap">
                                {{ $run->started_at?->format('d/m H:i:s') }}
                            </td>
                            <td class="px-4 py-2 text-gray-200">
                                {{ $jobLabel($run->job_class) }}
                                <span class="block text-[11px] text-gray-600 font-mono">{{ $run->shortName() }}</span>
                            </td>
                            <td class="px-4 py-2">
                                <a href="{{ route('admin.logs.jobs', ['group' => $run->job_group]) }}"
                                   class="text-xs text-gray-400 hover:text-sky-300">{{ $groupLabel($run->job_group) }}</a>
                            </td>
                            <td class="px-4 py-2"><x-admin.badge :status="$statusBadge($run->status)">{{ $statusLabel($run->status) }}</x-admin.badge></td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ $run->durationFormatted() }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">
                                @if ($run->status === 'failed')
                                    <details>
                                        <summary class="cursor-pointer text-red-300">{{ \Illuminate\Support\Str::limit($run->error_message, 120) }}</summary>
                                        <pre class="mt-2 p-2 bg-gray-950 rounded text-[11px] text-gray-500 whitespace-pre-wrap break-all max-h-64 overflow-y-auto">{{ $run->error_message }}

{{ $run->error_trace }}</pre>
                                    </details>
                                @else
                                    {{ $run->message }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $runs->links() }}</div>
    @endif
</div>
@endsection
