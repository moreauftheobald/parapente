@php
    $selectCls = 'w-full px-2.5 py-1.5 text-sm bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40 cursor-pointer';
@endphp

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

        <x-admin.section title="Import — ParaglidingEarth" icon="fa-solid fa-cloud-arrow-down" color="sky" class="mb-6">
            <span class="text-xs text-gray-500 mb-3 block">
                {{ number_format($sitesPge, 0, ',', ' ') }} importé{{ $sitesPge > 1 ? 's' : '' }}
                · {{ number_format($sitesTotal, 0, ',', ' ') }} au total
            </span>
            <form method="POST" action="{{ route('admin.sync.sites') }}"
                  onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<i class=&quot;fa-solid fa-spinner fa-spin&quot;></i> Import en cours…';">
                @csrf
                <div class="flex items-center gap-4 flex-wrap">
                    <x-admin.field name="iso" label="Pays"
                                   hint="Code ISO du pays à importer depuis ParaglidingEarth. Les nouveaux sites sont créés inactifs.">
                        <select name="iso" id="iso" class="{{ $selectCls }}">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}" @selected(old('iso', 'fr') === $code)>{{ $name }} ({{ strtoupper($code) }})</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.input name="limit" label="Limite" type="number"
                                   min="1" max="20000" placeholder="toutes"
                                   :value="old('limit')"
                                   hint="Nombre max de sites à importer (vide = tous)."
                                   wrapperClass="w-40" />
                    <x-admin.button type="submit" variant="primary" icon="fa-solid fa-cloud-arrow-down" class="self-center">
                        Importer
                    </x-admin.button>
                </div>
            </form>
        </x-admin.section>

        {{-- Couverture prévisions sites ──────────────────────────────── --}}
        @php require resource_path('views/admin/_coverage-helpers.php'); @endphp

        <h2 class="text-xs uppercase tracking-wider text-gray-400 mb-3">
            <i class="fa-solid fa-mountain-sun text-sky-400"></i>
            Prévisions sites — J → J+4
            <span class="text-gray-600 normal-case">({{ $siteForecasts['sites_active'] }} sites actifs)</span>
        </h2>

        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto mb-4">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 border-b border-gray-800 text-gray-400">
                    <tr>
                        <th class="text-left px-3 py-2 font-medium sticky left-0 bg-gray-900 z-10">Modèle</th>
                        @foreach ($siteForecasts['days'] as $day)
                            <th class="text-center px-3 py-2 font-medium" title="{{ $day->format('Y-m-d') }}">
                                <div class="text-white">{{ $fmtDay($day, $today) }}</div>
                                <div class="text-[10px] text-gray-500">{{ $day->isoFormat('D MMM') }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/70">
                    @forelse ($siteForecasts['rows'] as $row)
                        <tr>
                            <td class="px-3 py-2 sticky left-0 bg-gray-900 z-10">
                                <div class="text-white">{{ $row['model']->name }}</div>
                                <div class="text-xs text-gray-500 font-mono">
                                    {{ $row['model']->code }}
                                    <span class="ml-1 text-gray-600">· {{ $row['model']->max_horizon_h }}h</span>
                                </div>
                            </td>
                            @foreach ($row['cells'] as $cell)
                                <td class="px-2 py-2 text-center">
                                    <span class="inline-block min-w-[64px] px-2 py-1 rounded border text-xs font-mono {{ $coverageCellClass($cell) }}"
                                          title="{{ $cellTooltip($cell) }}">
                                        {{ $fmtCell($cell) }}
                                    </span>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($siteForecasts['days']) + 1 }}" class="px-3 py-6 text-center text-gray-500">Aucun modèle actif.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin._coverage-legend')
