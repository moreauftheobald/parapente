@extends('layouts.admin')
@section('title', 'Stations météo')

@php
    use App\Models\WeatherStation;

    $sortLink = function (string $field, string $label) use ($sort, $dir) {
        $newDir = ($sort === $field && $dir === 'asc') ? 'desc' : 'asc';
        $arrow  = $sort === $field ? ($dir === 'asc' ? '↑' : '↓') : '';
        $params = array_merge(request()->query(), ['sort' => $field, 'dir' => $newDir]);
        return sprintf(
            '<a href="?%s" class="text-gray-300 hover:text-white">%s <span class="text-sky-400">%s</span></a>',
            http_build_query($params), e($label), $arrow
        );
    };

    $inputCls  = 'w-full mt-1 px-2 py-1 text-xs bg-gray-950 border border-gray-700 rounded text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $selectCls = $inputCls . ' cursor-pointer';

    $ageBadge = function (?\Carbon\Carbon $obsAt): array {
        if (! $obsAt) return ['—', 'bg-gray-800 text-gray-500 border-gray-700'];
        $min = abs(now()->diffInMinutes($obsAt));
        if ($min < 90)   return [round($min) . " min",    'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'];
        if ($min < 360)  return [round($min/60, 1) . " h", 'bg-amber-500/15 text-amber-300 border-amber-500/30'];
        if ($min < 1440) return [round($min/60, 1) . " h", 'bg-red-500/15 text-red-300 border-red-500/30'];
        return [round($min/1440) . " j", 'bg-gray-700 text-gray-400 border-gray-700'];
    };

    $networkBadge = function (string $network): string {
        $meta = WeatherStation::NETWORKS[$network] ?? null;
        $label = $meta['label'] ?? $network;
        $color = $meta['color'] ?? '#6b7280';
        return sprintf(
            '<span class="inline-flex items-center gap-1.5 px-2 py-0.5 text-xs rounded border border-gray-700" style="color:%s;border-color:%s40;background:%s15"><i class="fa-solid fa-tower-broadcast text-[10px]"></i> %s</span>',
            $color, $color, $color, e($label)
        );
    };
@endphp

@section('content')
<div class="max-w-7xl">
    <x-admin.page-title title="Stations météo">
        <x-slot:subtitle>
            {{ number_format($totalCount, 0, ',', ' ') }} station{{ $totalCount > 1 ? 's' : '' }} en base
            · {{ number_format($stations->total(), 0, ',', ' ') }} après filtre
        </x-slot:subtitle>
        <x-slot:actions>
            @if (request()->query())
                <a href="{{ route('admin.weather-stations.index') }}" class="text-xs text-gray-400 hover:text-white transition" title="Réinitialiser les filtres">
                    <i class="fa-solid fa-rotate-left"></i> Réinitialiser
                </a>
            @endif
            <x-admin.button form="filters-form" type="submit" variant="primary" size="sm" icon="fa-solid fa-filter">
                Appliquer
            </x-admin.button>
        </x-slot:actions>
    </x-admin.page-title>

    <form id="filters-form" method="GET"></form>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left align-top">
                        {!! $sortLink('name', 'Station') !!}
                        <input form="filters-form" name="search" type="search"
                               value="{{ request('search') }}" placeholder="Nom ou code…"
                               class="{{ $inputCls }}">
                    </th>
                    <th class="px-4 py-3 text-left align-top w-40">
                        {!! $sortLink('network', 'Réseau') !!}
                        <select form="filters-form" name="network" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            @foreach ($networks as $n)
                                <option value="{{ $n }}" @selected(request('network') === $n)>
                                    {{ WeatherStation::NETWORKS[$n]['label'] ?? $n }}
                                </option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-left align-top w-20">
                        {!! $sortLink('country_code', 'Pays') !!}
                        <select form="filters-form" name="country_code" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            @foreach ($countryCodes as $cc)
                                <option value="{{ $cc }}" @selected(request('country_code') === $cc)>{{ $cc }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-left align-top w-40">
                        {!! $sortLink('department', 'Département') !!}
                        <select form="filters-form" name="department" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— tous —</option>
                            @foreach ($departments as $d)
                                <option value="{{ $d }}" @selected(request('department') === $d)>{{ $d }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th class="px-4 py-3 text-left align-top w-44 text-gray-300">Dernière obs.</th>
                    <th class="px-4 py-3 text-left align-top w-48 text-gray-300">Vent / Temp. / Pression</th>
                    <th class="px-4 py-3 text-center align-top w-32">
                        {!! $sortLink('active', 'Statut') !!}
                        <select form="filters-form" name="active" class="{{ $selectCls }}" onchange="this.form.submit()">
                            <option value="">— toutes —</option>
                            <option value="1" @selected(request('active') === '1')>Actives</option>
                            <option value="0" @selected(request('active') === '0')>Inactives</option>
                        </select>
                    </th>
                    <th class="px-4 py-3 text-right align-top w-24 text-gray-300">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($stations as $station)
                    @php
                        $obs = $station->latestObservation;
                        [$ageLabel, $ageCls] = $ageBadge($obs?->observed_at);
                    @endphp
                    <tr class="hover:bg-gray-800/50">
                        <td class="px-4 py-3">
                            <div class="text-gray-100 font-medium">{{ $station->name ?: '(sans nom)' }}</div>
                            <div class="text-xs text-gray-500 font-mono">
                                #{{ $station->external_id }}
                                · {{ number_format((float) $station->latitude, 4) }}, {{ number_format((float) $station->longitude, 4) }}
                                @if ($station->altitude_m)
                                    · {{ $station->altitude_m }}m
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-3">{!! $networkBadge($station->network) !!}</td>
                        <td class="px-4 py-3 text-gray-400">
                            @if ($station->country_code)
                                <span class="font-mono text-xs px-1.5 py-0.5 rounded bg-gray-800 text-gray-300">{{ $station->country_code }}</span>
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-400 truncate" title="{{ $station->department }}">{{ $station->department ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($obs)
                                <div class="text-xs text-gray-400 font-mono">{{ $obs->observed_at->format('d/m H:i') }}</div>
                                <span class="inline-block mt-1 px-1.5 py-0.5 text-[10px] rounded border {{ $ageCls }}">
                                    il y a {{ $ageLabel }}
                                </span>
                            @else
                                <span class="text-xs text-gray-600">Aucune</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs font-mono text-gray-400">
                            @if ($obs && ($obs->wind_speed_avg !== null || $obs->temperature !== null))
                                @if ($obs->wind_speed_avg !== null)
                                    <div>
                                        <i class="fa-solid fa-wind text-emerald-400"></i>
                                        {{ number_format((float) $obs->wind_speed_avg, 1) }} km/h
                                        @if ($obs->wind_direction !== null)
                                            @ {{ $obs->wind_direction }}°
                                        @endif
                                    </div>
                                @endif
                                <div class="flex items-center gap-3 mt-0.5">
                                    @if ($obs->temperature !== null)
                                        <span class="text-gray-500">
                                            <i class="fa-solid fa-temperature-half"></i>
                                            {{ number_format((float) $obs->temperature, 1) }}°C
                                        </span>
                                    @endif
                                    @if ($obs->pressure_hpa !== null)
                                        <span class="text-gray-500">
                                            <i class="fa-solid fa-gauge"></i>
                                            {{ number_format((float) $obs->pressure_hpa, 0) }} hPa
                                        </span>
                                    @endif
                                </div>
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($station->active)
                                <i class="fa-solid fa-circle-check text-emerald-400 text-xl" title="Active"></i>
                            @else
                                <i class="fa-solid fa-circle-xmark text-red-400 text-xl" title="Inactive"></i>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1">
                                <a href="{{ route('admin.weather-stations.show', $station) }}"
                                   class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-700 text-gray-300 hover:bg-gray-800 hover:text-white transition"
                                   title="Détail">
                                    <i class="fa-solid fa-eye text-xs"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.weather-stations.toggle', $station) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded border transition
                                                @class([
                                                    'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $station->active,
                                                    'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $station->active,
                                                ])"
                                            title="{{ $station->active ? 'Désactiver' : 'Activer' }}">
                                        <i class="fa-solid fa-power-off text-xs"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-0 py-0">
                            <x-admin.empty-state icon="fa-solid fa-tower-broadcast" message="Aucune station météo." class="border-0 rounded-none" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $stations->links() }}
    </div>

    <p class="text-xs text-gray-500 mt-4">
        <i class="fa-solid fa-circle-info"></i>
        Les stations sont auto-découvertes par les jobs de fetch horaires.
        Quatre réseaux disponibles :
        @foreach (WeatherStation::NETWORKS as $key => $meta)
            <span style="color:{{ $meta['color'] }}"><i class="fa-solid fa-tower-broadcast"></i> {{ $meta['label'] }}</span>{{ ! $loop->last ? ',' : '.' }}
        @endforeach
    </p>
</div>
@endsection
