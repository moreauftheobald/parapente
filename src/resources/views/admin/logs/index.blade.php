@extends('layouts.admin')
@section('title', 'Logs / monitoring')

@php
    $levelClass = function (string $lvl): string {
        return match ($lvl) {
            'EMERGENCY','ALERT','CRITICAL','ERROR' => 'bg-red-500/15 text-red-300 border-red-500/30',
            'WARNING'                              => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
            'NOTICE','INFO'                        => 'bg-sky-500/15 text-sky-300 border-sky-500/30',
            'DEBUG'                                => 'bg-gray-700 text-gray-400 border-gray-600',
            default                                => 'bg-gray-800 text-gray-300 border-gray-700',
        };
    };
    $fmtSize = function (?int $bytes): string {
        if ($bytes === null) return '—';
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' Ko';
        return number_format($bytes / 1048576, 1) . ' Mo';
    };
@endphp

@section('content')
<div class="max-w-6xl">
    <x-admin.page-title title="Logs & monitoring">
        <x-slot:subtitle>
            Volumétrie de la base + dernières lignes du fichier <code class="text-gray-400 font-mono">{{ $logPath }}</code> ({{ $fmtSize($logSize) }}).
        </x-slot:subtitle>
    </x-admin.page-title>

    {{-- Onglets --}}
    @include('admin.logs._tabs', ['active' => 'laravel'])

    {{-- ─────────────────────────────────────────────
         Compteurs DB
    ───────────────────────────────────────────────── --}}
    <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
        <i class="fa-solid fa-database text-violet-400"></i> Volumétrie base
    </h2>
    @php
        // Mapping en dur pour que Tailwind JIT détecte les classes (sinon
        // border-{{ $color }}-500/20 ne serait pas généré au build).
        $cardClasses = [
            'sky'     => 'border-sky-500/20 text-sky-300',
            'emerald' => 'border-emerald-500/20 text-emerald-300',
            'violet'  => 'border-violet-500/20 text-violet-300',
            'amber'   => 'border-amber-500/20 text-amber-300',
        ];
        $cards = [
            ['Sites',            $counts['sites'],             $counts['sites_active'].' actifs',         'fa-mountain-sun',    'sky'],
            ['Balises',          $counts['balises'],           $counts['balises_active'].' actives',      'fa-tower-broadcast', 'emerald'],
            ['Modèles',          $counts['weather_models'],    $counts['weather_models_active'].' actifs','fa-cloud',           'violet'],
            ['Forecasts',        $counts['forecasts'],         'lignes en base',                          'fa-cloud-bolt',      'amber'],
            ['Site scores',      $counts['site_scores'],       'lignes en base',                          'fa-chart-simple',    'sky'],
            ['Lectures balises', $counts['balise_readings'],   'historique',                              'fa-wave-square',     'emerald'],
            ['Archive balises',  $counts['forecast_archive_balises'], 'fenêtre 30j',                      'fa-box-archive',     'violet'],
        ];
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
        @foreach ($cards as [$label, $n, $sub, $icon, $color])
            <div class="bg-gray-900 border {{ explode(' ', $cardClasses[$color])[0] }} rounded-xl p-4">
                <div class="flex items-center gap-2 text-xs uppercase tracking-wider {{ explode(' ', $cardClasses[$color])[1] }} mb-1">
                    <i class="fa-solid {{ $icon }}"></i> {{ $label }}
                </div>
                <div class="text-2xl font-mono text-white">{{ number_format($n, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-500 mt-0.5">{{ $sub }}</div>
            </div>
        @endforeach
    </div>

    {{-- ─────────────────────────────────────────────
         Logs Laravel
         (les exécutions des jobs vivent dans l'onglet « Jobs »)
    ───────────────────────────────────────────────── --}}
    <div class="flex items-baseline justify-between mb-3">
        <h2 class="text-xs uppercase tracking-wider text-gray-400">
            <i class="fa-solid fa-scroll text-amber-400"></i> Logs Laravel ({{ count($entries) }} entrées affichées)
        </h2>
        <form method="GET" class="flex items-center gap-2">
            <input type="text" name="search" value="{{ $search }}" placeholder="Rechercher dans les messages…"
                   class="px-3 py-1.5 bg-gray-950 border border-gray-700 rounded text-xs text-gray-100 focus:outline-none focus:border-sky-500 w-64">
            <select name="level" onchange="this.form.submit()"
                    class="px-3 py-1.5 bg-gray-950 border border-gray-700 rounded text-xs text-gray-100 focus:outline-none focus:border-sky-500 cursor-pointer">
                <option value="">Tous niveaux</option>
                @foreach (['ERROR' => 'Errors', 'WARNING' => 'Warnings', 'INFO' => 'Info', 'DEBUG' => 'Debug'] as $val => $lbl)
                    <option value="{{ $val }}" @selected($level === $val)>{{ $lbl }}</option>
                @endforeach
            </select>
            <x-admin.button type="submit" variant="primary" size="sm" icon="fa-solid fa-filter">
                Filtrer
            </x-admin.button>
            @if ($level || $search)
                <a href="{{ route('admin.logs.index') }}" class="text-xs text-gray-400 hover:text-white transition">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            @endif
        </form>
    </div>

    @if (empty($entries))
        <x-admin.empty-state icon="fa-solid fa-scroll" message="Aucune entrée dans la fenêtre lue." />
    @else
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
            <div class="divide-y divide-gray-800 max-h-[60vh] overflow-y-auto">
                @foreach ($entries as $e)
                    <div class="px-4 py-2.5 hover:bg-gray-800/40">
                        <div class="flex items-baseline gap-3 mb-1">
                            <span class="text-xs font-mono text-gray-500 shrink-0">{{ $e['time'] }}</span>
                            <span class="px-2 py-0.5 text-[10px] font-mono rounded border {{ $levelClass($e['level']) }}">
                                {{ $e['level'] }}
                            </span>
                        </div>
                        <pre class="text-xs font-mono text-gray-300 whitespace-pre-wrap break-all">{{ $e['message'] }}</pre>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
