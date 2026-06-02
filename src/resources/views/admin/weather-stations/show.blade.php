@extends('layouts.admin')
@section('title', 'Station · ' . ($station->name ?: $station->external_id))

@php
    use App\Models\WeatherStation;
    $netMeta = WeatherStation::NETWORKS[$station->network] ?? ['label' => $station->network, 'color' => '#6b7280'];
@endphp

@section('content')
<div class="max-w-5xl">
    <div class="bg-gradient-to-r from-sky-500/10 via-gray-900 to-gray-900 border border-sky-500/20 rounded-xl p-5 mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
                <i class="fa-solid fa-tower-broadcast" style="color:{{ $netMeta['color'] }}"></i>
                {{ $station->name ?: '(sans nom)' }}
                @if ($station->active)
                    <i class="fa-solid fa-circle-check text-emerald-400 text-base" title="Active"></i>
                @else
                    <i class="fa-solid fa-circle-xmark text-red-400 text-base" title="Inactive"></i>
                @endif
            </h1>
            <p class="text-xs text-gray-500 mt-1 font-mono">
                <span class="px-2 py-0.5 rounded border border-gray-700" style="color:{{ $netMeta['color'] }}">{{ $netMeta['label'] }}</span>
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">#{{ $station->external_id }}</span>
                <span class="text-gray-600">· {{ number_format((float) $station->latitude, 5) }}, {{ number_format((float) $station->longitude, 5) }}</span>
                @if ($station->altitude_m)
                    <span class="text-gray-600">· {{ $station->altitude_m }} m</span>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('admin.weather-stations.toggle-panel', $station) }}" class="inline">
                @csrf
                <button type="submit"
                        title="Inclure cette station dans le panel de calcul de fiabilité des modèles."
                        class="px-3 py-1.5 text-xs rounded border transition
                            @class([
                                'border-indigo-500/40 text-indigo-300 hover:bg-indigo-500/10' => ! $station->in_reliability_panel,
                                'border-violet-400 bg-violet-500/15 text-violet-100 hover:bg-violet-500/25' => $station->in_reliability_panel,
                            ])">
                    <i class="fa-solid fa-flask-vial"></i>
                    {{ $station->in_reliability_panel ? 'Panel fiabilité ✓' : 'Panel fiabilité' }}
                </button>
            </form>
            <form method="POST" action="{{ route('admin.weather-stations.toggle', $station) }}" class="inline">
                @csrf
                <button type="submit"
                        class="px-3 py-1.5 text-xs rounded border transition
                            @class([
                                'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $station->active,
                                'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $station->active,
                            ])">
                    <i class="fa-solid fa-power-off"></i> {{ $station->active ? 'Désactiver' : 'Activer' }}
                </button>
            </form>
            <a href="{{ route('admin.weather-stations.index') }}" class="text-sm text-gray-400 hover:text-white transition flex items-center gap-2">
                <i class="fa-solid fa-arrow-left"></i> Liste
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
                <i class="fa-solid fa-chart-simple text-sky-400"></i> Statistiques
            </h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Observations totales</dt>
                    <dd class="text-gray-200 font-mono">{{ number_format($station->observations()->count(), 0, ',', ' ') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Observations &lt; 24h</dt>
                    <dd class="text-gray-200 font-mono">{{ $station->observations()->where('observed_at', '>=', now()->subDay())->count() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Réseau</dt>
                    <dd class="font-mono text-xs">
                        <span style="color:{{ $netMeta['color'] }}">{{ $netMeta['label'] }}</span>
                    </dd>
                </div>
                @if ($station->department)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Département</dt>
                        <dd class="text-gray-300 text-xs">{{ $station->department }}</dd>
                    </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-gray-500">Créée le</dt>
                    <dd class="text-gray-400 font-mono">{{ $station->created_at->format('d/m/Y') }}</dd>
                </div>
            </dl>
        </div>

        <div class="bg-gray-900 border border-emerald-500/20 rounded-xl p-5 lg:col-span-2">
            <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
                <i class="fa-solid fa-clock text-emerald-400"></i> Dernière observation
            </h2>
            @if ($station->latestObservation)
                @php $obs = $station->latestObservation; @endphp
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Horodatage</div>
                        <div class="text-sm font-mono text-gray-200">{{ $obs->observed_at->format('d/m H:i') }}</div>
                        <div class="text-[11px] text-gray-500">il y a {{ $obs->observed_at->diffForHumans(null, true) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Direction</div>
                        <div class="text-sm font-mono text-gray-200">{{ $obs->wind_direction !== null ? $obs->wind_direction . '°' : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Vitesse moy</div>
                        <div class="text-sm font-mono text-emerald-300">{{ $obs->wind_speed_avg !== null ? number_format((float) $obs->wind_speed_avg, 1) . ' km/h' : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Rafale max</div>
                        <div class="text-sm font-mono text-amber-300">{{ $obs->wind_speed_max !== null ? number_format((float) $obs->wind_speed_max, 1) . ' km/h' : '—' }}</div>
                        @if ($obs->wind_direction_gust !== null)
                            <div class="text-[11px] text-gray-600">dir {{ $obs->wind_direction_gust }}°</div>
                        @endif
                    </div>
                    @if ($obs->wind_speed_max_10m !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Vent max 10 min</div>
                            <div class="text-sm font-mono text-orange-300">{{ number_format((float) $obs->wind_speed_max_10m, 1) }} km/h</div>
                            @if ($obs->wind_direction_max !== null)
                                <div class="text-[11px] text-gray-600">dir {{ $obs->wind_direction_max }}°</div>
                            @endif
                        </div>
                    @endif
                    @if ($obs->temperature !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Température</div>
                            <div class="text-sm font-mono text-gray-200">{{ number_format((float) $obs->temperature, 1) }}°C</div>
                            @if ($obs->temperature_min !== null || $obs->temperature_max !== null)
                                <div class="text-[11px] text-gray-600">
                                    {{ $obs->temperature_min !== null ? number_format((float) $obs->temperature_min, 1) : '?' }}
                                    → {{ $obs->temperature_max !== null ? number_format((float) $obs->temperature_max, 1) : '?' }}°C
                                </div>
                            @endif
                        </div>
                    @endif
                    @if ($obs->humidity !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Humidité</div>
                            <div class="text-sm font-mono text-gray-200">{{ $obs->humidity }}%</div>
                            @if ($obs->humidity_min !== null || $obs->humidity_max !== null)
                                <div class="text-[11px] text-gray-600">
                                    {{ $obs->humidity_min ?? '?' }} → {{ $obs->humidity_max ?? '?' }}%
                                </div>
                            @endif
                        </div>
                    @endif
                    @if ($obs->pressure_hpa !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Pression</div>
                            <div class="text-sm font-mono text-gray-200">{{ number_format((float) $obs->pressure_hpa, 1) }} hPa</div>
                        </div>
                    @endif
                    @if ($obs->precipitation_mm !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Précipitations</div>
                            <div class="text-sm font-mono text-gray-200">{{ number_format((float) $obs->precipitation_mm, 1) }} mm</div>
                        </div>
                    @endif
                    @if ($obs->dew_point !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Point de rosée</div>
                            <div class="text-sm font-mono text-gray-200">{{ number_format((float) $obs->dew_point, 1) }}°C</div>
                        </div>
                    @endif
                    @if ($obs->visibility_m !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Visibilité</div>
                            <div class="text-sm font-mono text-gray-200">{{ number_format($obs->visibility_m / 1000, 1) }} km</div>
                        </div>
                    @endif
                    @if ($obs->cloud_cover_pct !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Nébulosité</div>
                            <div class="text-sm font-mono text-gray-200">{{ $obs->cloud_cover_pct }}%</div>
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-500 italic">Aucune observation en base.</p>
            @endif
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-gray-800">
            <h2 class="text-xs uppercase tracking-wider text-gray-400">
                <i class="fa-solid fa-list text-violet-400"></i> 50 dernières observations
            </h2>
        </div>
        @if ($recentObservations->isEmpty())
            <p class="px-5 py-12 text-center text-sm text-gray-500">Aucune observation.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-xs uppercase tracking-wider text-gray-400 border-b border-gray-800">
                    <tr>
                        <th class="px-4 py-2 text-left">Date / heure</th>
                        <th class="px-4 py-2 text-right">Direction</th>
                        <th class="px-4 py-2 text-right">V. moy</th>
                        <th class="px-4 py-2 text-right">Rafale</th>
                        <th class="px-4 py-2 text-right">Temp.</th>
                        <th class="px-4 py-2 text-right">Humid.</th>
                        <th class="px-4 py-2 text-right">Pression</th>
                        <th class="px-4 py-2 text-right">Précip.</th>
                        <th class="px-4 py-2 text-right">Visib.</th>
                        <th class="px-4 py-2 text-right">Nébu.</th>
                        <th class="px-4 py-2 text-right">Rosée</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800 font-mono text-xs">
                    @foreach ($recentObservations as $obs)
                        <tr class="hover:bg-gray-800/50">
                            <td class="px-4 py-2 text-gray-300">{{ $obs->observed_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2 text-right text-gray-300">
                                {{ $obs->wind_direction !== null ? $obs->wind_direction . '°' : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-emerald-300">
                                {{ $obs->wind_speed_avg !== null ? number_format((float) $obs->wind_speed_avg, 1) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-amber-300">
                                {{ $obs->wind_speed_max !== null ? number_format((float) $obs->wind_speed_max, 1) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $obs->temperature !== null ? number_format((float) $obs->temperature, 1) . '°' : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $obs->humidity !== null ? $obs->humidity . '%' : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $obs->pressure_hpa !== null ? number_format((float) $obs->pressure_hpa, 0) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $obs->precipitation_mm !== null ? number_format((float) $obs->precipitation_mm, 1) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $obs->visibility_m !== null ? number_format($obs->visibility_m / 1000, 1) . ' km' : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $obs->cloud_cover_pct !== null ? $obs->cloud_cover_pct . '%' : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $obs->dew_point !== null ? number_format((float) $obs->dew_point, 1) . '°' : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="bg-red-500/5 border border-red-500/30 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-red-300 mb-2 flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> Zone dangereuse
        </h3>
        <div class="flex items-center justify-between">
            <p class="text-xs text-gray-500 max-w-md">
                Suppression de la station et de toutes ses observations historiques.
                <strong class="text-red-400">Irréversible.</strong>
                Note : si la station reste découvrable depuis son réseau, elle sera ré-importée au prochain cycle de découverte.
            </p>
            <form method="POST" action="{{ route('admin.weather-stations.destroy', $station) }}"
                  onsubmit="return confirm('Supprimer définitivement « {{ addslashes($station->name ?? $station->external_id) }} » et toutes ses observations ?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="px-4 py-2 bg-red-500/15 border border-red-500/40 text-red-300 hover:bg-red-500/25 hover:text-red-200 text-sm rounded-md transition flex items-center gap-2">
                    <i class="fa-solid fa-trash"></i> Supprimer
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
