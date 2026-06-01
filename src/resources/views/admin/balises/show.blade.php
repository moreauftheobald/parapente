@extends('layouts.admin')
@section('title', 'Balise · ' . $balise->name)

@section('content')
<div>
    <div class="bg-gradient-to-r from-sky-500/10 via-gray-900 to-gray-900 border border-sky-500/20 rounded-xl p-5 mb-6 flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-white flex items-center gap-2">
                <i class="fa-solid fa-tower-broadcast text-sky-400"></i>
                {{ $balise->name }}
                @if ($balise->active)
                    <i class="fa-solid fa-circle-check text-emerald-400 text-base" title="Active"></i>
                @else
                    <i class="fa-solid fa-circle-xmark text-red-400 text-base" title="Inactive"></i>
                @endif
            </h1>
            <p class="text-xs text-gray-500 mt-1 font-mono">
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">{{ $balise->source }}</span>
                <span class="px-2 py-0.5 rounded bg-gray-800 border border-gray-700">#{{ $balise->external_id }}</span>
                <span class="text-gray-600">· {{ number_format((float) $balise->latitude, 5) }}, {{ number_format((float) $balise->longitude, 5) }}</span>
                @if ($balise->altitude_m)
                    <span class="text-gray-600">· {{ $balise->altitude_m }} m</span>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('admin.balises.toggle-compare-panel', $balise) }}" class="inline">
                @csrf
                <button type="submit"
                        title="Inclure cette balise dans le panel de validation triple-consensus (phase 2.5). Cf. FF_model_reliability.md."
                        class="px-3 py-1.5 text-xs rounded border transition
                            @class([
                                'border-indigo-500/40 text-indigo-300 hover:bg-indigo-500/10' => ! $balise->in_consensus_compare_panel,
                                'border-violet-400 bg-violet-500/15 text-violet-100 hover:bg-violet-500/25' => $balise->in_consensus_compare_panel,
                            ])">
                    <i class="fa-solid fa-flask-vial"></i>
                    {{ $balise->in_consensus_compare_panel ? 'Panel test fiabilité ✓' : 'Panel test fiabilité' }}
                </button>
            </form>
            <form method="POST" action="{{ route('admin.balises.toggle', $balise) }}" class="inline">
                @csrf
                <button type="submit"
                        class="px-3 py-1.5 text-xs rounded border transition
                            @class([
                                'border-amber-500/40 text-amber-300 hover:bg-amber-500/10' => $balise->active,
                                'border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10' => ! $balise->active,
                            ])">
                    <i class="fa-solid fa-power-off"></i> {{ $balise->active ? 'Désactiver' : 'Activer' }}
                </button>
            </form>
            <a href="{{ route('admin.balises.index') }}" class="text-sm text-gray-400 hover:text-white transition flex items-center gap-2">
                <i class="fa-solid fa-arrow-left"></i> Liste
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
        {{-- Statistiques rapides --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
                <i class="fa-solid fa-chart-simple text-sky-400"></i> Statistiques
            </h2>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Lectures totales</dt>
                    <dd class="text-gray-200 font-mono">{{ number_format($balise->readings()->count(), 0, ',', ' ') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Lectures &lt; 24h</dt>
                    <dd class="text-gray-200 font-mono">{{ $balise->readings()->where('read_at', '>=', now()->subDay())->count() }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Créée le</dt>
                    <dd class="text-gray-400 font-mono">{{ $balise->created_at->format('d/m/Y') }}</dd>
                </div>
            </dl>
        </div>

        {{-- Dernière lecture --}}
        <div class="bg-gray-900 border border-emerald-500/20 rounded-xl p-5 lg:col-span-2">
            <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
                <i class="fa-solid fa-clock text-emerald-400"></i> Dernière lecture
            </h2>
            @if ($balise->latestReading)
                @php $r = $balise->latestReading; @endphp
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Horodatage</div>
                        <div class="text-sm font-mono text-gray-200">{{ $r->read_at->format('d/m H:i:s') }}</div>
                        <div class="text-[11px] text-gray-500">il y a {{ $r->read_at->diffForHumans(null, true) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Direction</div>
                        <div class="text-sm font-mono text-gray-200">{{ $r->wind_direction !== null ? $r->wind_direction . '°' : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Vitesse moy</div>
                        <div class="text-sm font-mono text-emerald-300">{{ $r->wind_speed_avg !== null ? number_format((float) $r->wind_speed_avg, 1) . ' km/h' : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 mb-1">Rafale max</div>
                        <div class="text-sm font-mono text-amber-300">{{ $r->wind_speed_max !== null ? number_format((float) $r->wind_speed_max, 1) . ' km/h' : '—' }}</div>
                    </div>
                    @if ($r->temperature !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Température</div>
                            <div class="text-sm font-mono text-gray-200">{{ number_format((float) $r->temperature, 1) }}°C</div>
                        </div>
                    @endif
                    @if ($r->humidity !== null)
                        <div>
                            <div class="text-xs text-gray-500 mb-1">Humidité</div>
                            <div class="text-sm font-mono text-gray-200">{{ $r->humidity }}%</div>
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-500 italic">Aucune lecture en base.</p>
            @endif
        </div>
    </div>

    {{-- Historique récent --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="px-5 py-3 border-b border-gray-800">
            <h2 class="text-xs uppercase tracking-wider text-gray-400">
                <i class="fa-solid fa-list text-violet-400"></i> 50 dernières lectures
            </h2>
        </div>
        @if ($recentReadings->isEmpty())
            <p class="px-5 py-12 text-center text-sm text-gray-500">Aucune lecture.</p>
        @else
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-xs uppercase tracking-wider text-gray-400 border-b border-gray-800">
                    <tr>
                        <th class="px-4 py-2 text-left">Date / heure</th>
                        <th class="px-4 py-2 text-right">Direction</th>
                        <th class="px-4 py-2 text-right">Vitesse moy</th>
                        <th class="px-4 py-2 text-right">Min / Max</th>
                        <th class="px-4 py-2 text-right">Temp.</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800 font-mono text-xs">
                    @foreach ($recentReadings as $r)
                        <tr class="hover:bg-gray-800/50">
                            <td class="px-4 py-2 text-gray-300">{{ $r->read_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2 text-right text-gray-300">
                                {{ $r->wind_direction !== null ? $r->wind_direction . '°' : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-emerald-300">
                                {{ $r->wind_speed_avg !== null ? number_format((float) $r->wind_speed_avg, 1) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-500">
                                {{ $r->wind_speed_min !== null ? number_format((float) $r->wind_speed_min, 0) : '—' }}
                                /
                                {{ $r->wind_speed_max !== null ? number_format((float) $r->wind_speed_max, 0) : '—' }}
                            </td>
                            <td class="px-4 py-2 text-right text-gray-400">
                                {{ $r->temperature !== null ? number_format((float) $r->temperature, 1) . '°' : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Zone dangereuse --}}
    <div class="bg-red-500/5 border border-red-500/30 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-red-300 mb-2 flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> Zone dangereuse
        </h3>
        <div class="flex items-center justify-between">
            <p class="text-xs text-gray-500 max-w-md">
                Suppression de la balise et de toutes ses lectures historiques.
                <strong class="text-red-400">Irréversible.</strong>
                Note : si la balise reste découvrable depuis sa source (ex: PiouPiou),
                elle sera ré-importée au prochain `balises:discover`.
            </p>
            <form method="POST" action="{{ route('admin.balises.destroy', $balise) }}"
                  onsubmit="return confirm('Supprimer définitivement « {{ addslashes($balise->name) }} » et toutes ses lectures ?');">
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
