@if (session('sync_output'))
            <div class="mb-6 bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
                <div class="px-4 py-2 text-xs uppercase tracking-wider text-gray-500 border-b border-gray-800">
                    Résultat de la dernière opération
                </div>
                <pre class="px-4 py-3 text-xs text-gray-300 whitespace-pre-wrap font-mono leading-relaxed">{{ session('sync_output') }}</pre>
            </div>
        @endif

        @if ($errors->any())
            <x-admin.alert type="error">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-admin.alert>
        @endif

        {{-- Découverte des balises ───────────────────────────────────── --}}
        <div class="space-y-6 mb-8">
            @foreach ([
                ['source' => 'pioupiou', 'title' => 'Découverte — PiouPiou',              'icon' => 'fa-solid fa-tower-broadcast', 'count' => $balisesPiou,  'desc' => 'Stations PiouPiou actives (dernières 24 h).'],
                ['source' => 'windy',    'title' => 'Découverte — Windy.com (Open Data)',  'icon' => 'fa-solid fa-wind',            'count' => $balisesWindy, 'desc' => 'Stations Windy publiées sous licence ouverte.'],
            ] as $src)
                <x-admin.section :title="$src['title']" :icon="$src['icon']" color="sky">
                    <span class="text-xs text-gray-500 mb-3 block">{{ number_format($src['count'], 0, ',', ' ') }} en base</span>

                    @if ($src['source'] === 'windy' && ! $windyKeyConfigured)
                        <x-admin.alert type="warning">
                            Clé API Windy non configurée.
                            <a href="{{ route('admin.settings.index') }}" class="underline hover:text-amber-200">Paramètres système</a>.
                        </x-admin.alert>
                    @endif

                    <form method="POST" action="{{ route('admin.sync.balises') }}"
                          onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Découverte en cours…';">
                        @csrf
                        <input type="hidden" name="source" value="{{ $src['source'] }}">
                        <div class="flex items-center gap-4 flex-wrap">
                            <x-admin.input name="lat_min" label="Lat min" type="number" step="0.01"
                                           :value="old('lat_min', $bbox['lat_min'])" wrapperClass="w-36" />
                            <x-admin.input name="lat_max" label="Lat max" type="number" step="0.01"
                                           :value="old('lat_max', $bbox['lat_max'])" wrapperClass="w-36" />
                            <x-admin.input name="lng_min" label="Lng min" type="number" step="0.01"
                                           :value="old('lng_min', $bbox['lng_min'])" wrapperClass="w-36" />
                            <x-admin.input name="lng_max" label="Lng max" type="number" step="0.01"
                                           :value="old('lng_max', $bbox['lng_max'])" wrapperClass="w-36" />
                            <x-admin.button type="submit" variant="primary" icon="fa-solid fa-tower-broadcast"
                                            class="self-center" :disabled="$src['source'] === 'windy' && ! $windyKeyConfigured">
                                Découvrir
                            </x-admin.button>
                        </div>
                    </form>
                </x-admin.section>
            @endforeach
        </div>

        {{-- Couverture prévisions balises ─────────────────────────────── --}}
        @php require resource_path('views/admin/_coverage-helpers.php'); @endphp

        <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
            <i class="fa-solid fa-box-archive text-violet-400"></i>
            Prévisions balises — J-7 → J+5
            <span class="text-gray-600 normal-case">({{ $baliseForecasts['balises_active'] }} balises actives, horizon archivé limité à J+2)</span>
        </h2>

        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-8">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                    <tr>
                        <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Modèle</th>
                        @foreach ($baliseForecasts['days'] as $day)
                            <th class="text-center px-2 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                                <div class="text-white text-[11px]">{{ $fmtDay($day, $today) }}</div>
                                <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D/MM') }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/70">
                    @forelse ($baliseForecasts['rows'] as $row)
                        <tr @class(['opacity-60' => ! $row['archived']])>
                            <td class="px-3 py-2 sticky left-0 bg-gray-900 z-10">
                                <div class="text-white">
                                    {{ $row['model']->name }}
                                    @unless ($row['archived'])
                                        <span class="ml-1 text-[10px] uppercase tracking-wider text-gray-500"
                                              title="Modèle d'horizon < 24h — exclu de l'archive balises par FetchBaliseForecastsJob">non archivé</span>
                                    @endunless
                                </div>
                                <div class="text-xs text-gray-500 font-mono">
                                    {{ $row['model']->code }}
                                    <span class="ml-1 text-gray-600">· {{ $row['model']->max_horizon_h }}h</span>
                                </div>
                            </td>
                            @foreach ($row['cells'] as $cell)
                                <td class="px-1 py-2 text-center">
                                    <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($cell) }}"
                                          title="{{ $cellTooltip($cell) }}">
                                        {{ $fmtCell($cell) }}
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($baliseForecasts['days']) + 1 }}" class="px-3 py-6 text-center text-gray-500">Aucun modèle actif ou aucune balise active.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Couverture relevés balises ────────────────────────────────── --}}
        <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
            <i class="fa-solid fa-tower-broadcast text-emerald-400"></i>
            Relevés balises — J-7 → J
            <span class="text-gray-600 normal-case">(agrégat horaire de <code>balise_readings_hourly</code>)</span>
        </h2>

        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-4">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                    <tr>
                        <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Réseau / Balise</th>
                        @foreach ($baliseReadings['days'] as $day)
                            <th class="text-center px-2 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                                <div class="text-white text-[11px]">{{ $fmtDay($day, $today) }}</div>
                                <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D/MM') }}</div>
                            </th>
                        @endforeach
                        <th class="text-right px-3 py-2 font-medium">Dernière réception</th>
                    </tr>
                </thead>
                @forelse ($baliseReadings['groups'] as $group)
                    <tbody class="divide-y divide-gray-800/70 border-t border-gray-800/70"
                           x-data="{ open: false }">
                        <tr class="bg-gray-800/40 hover:bg-gray-800/70 transition cursor-pointer select-none"
                            @click="open = !open">
                            <td class="px-3 py-2 sticky left-0 bg-gray-800/40 z-10">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-chevron-right w-3 text-gray-400 transition-transform"
                                       :class="open && 'rotate-90'"></i>
                                    <span class="text-white font-medium">{{ $group['label'] }}</span>
                                    <span class="text-xs text-gray-500">({{ $group['balises_count'] }} balise{{ $group['balises_count'] > 1 ? 's' : '' }})</span>
                                </div>
                            </td>
                            @foreach ($group['aggregate_cells'] as $pct)
                                <td class="px-1 py-2 text-center">
                                    <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($pct) }}"
                                          title="{{ $pct === null ? 'N/A' : 'Agrégat réseau : '.$pct.'%' }}">
                                        {{ $fmtPct($pct) }}
                                    </span>
                                </td>
                            @endforeach
                            <td class="px-3 py-2 text-right text-xs text-gray-500" x-show="!open">
                                <span class="text-gray-500">détail&hellip;</span>
                            </td>
                            <td class="px-3 py-2 text-right text-xs text-gray-500" x-show="open" x-cloak>
                                <span class="text-gray-500">réduire</span>
                            </td>
                        </tr>

                        @foreach ($group['balises'] as $row)
                            <tr x-show="open" x-cloak class="hover:bg-gray-800/30 transition">
                                <td class="px-3 py-1.5 sticky left-0 bg-gray-900 z-10 pl-8">
                                    <div class="text-white text-[13px]">{{ $row['balise']->name }}</div>
                                    <div class="text-[10px] text-gray-500 font-mono">#{{ $row['balise']->id }}</div>
                                </td>
                                @foreach ($row['cells'] as $pct)
                                    <td class="px-1 py-1.5 text-center">
                                        <span class="inline-block min-w-[52px] px-1.5 py-1 rounded border text-[11px] font-mono {{ $coverageCellClass($pct) }}"
                                              title="{{ $pct === null ? 'N/A' : $pct.'%' }}">
                                            {{ $fmtPct($pct) }}
                                        </span>
                                    </td>
                                @endforeach
                                <td class="px-3 py-1.5 text-right font-mono text-[11px] text-gray-300">{{ $fmtDateTime($row['last_reading_at']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="{{ count($baliseReadings['days']) + 2 }}" class="px-3 py-6 text-center text-gray-500">Aucune balise active.</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        @include('admin._coverage-legend')

        @push('styles')
            <style>[x-cloak] { display: none !important; }</style>
        @endpush
