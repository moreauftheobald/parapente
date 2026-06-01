@php
    $inputCls = 'w-full px-2.5 py-1.5 bg-gray-950 border border-gray-700 rounded-md text-sm text-gray-100 font-mono focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40';
    $field = fn (string $key) => str_replace('.', '__', $key);
    $mode = $schedulerSettings['consensus.scheduler.mode']['value'] ?? 'cron';
@endphp

@if ($errors->any())
    <x-admin.alert type="error">
        <ul class="list-disc list-inside">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-admin.alert>
@endif

<form method="POST" action="{{ route('admin.meteo.settings.orchestration') }}" x-data="{ mode: '{{ old($field('consensus.scheduler.mode'), $mode) }}' }">
    @csrf

    {{-- Mode ────────────────────────────────────────────────────── --}}
    <x-admin.section title="Mode de scheduling" icon="fa-solid fa-clock" color="sky" class="mb-6">
        <div class="flex items-center gap-6">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="{{ $field('consensus.scheduler.mode') }}" value="cron"
                       x-model="mode"
                       class="text-sky-500 border-gray-700 bg-gray-800 focus:ring-sky-500/30">
                <span class="text-sm text-gray-300">Cron horaire</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer opacity-50">
                <input type="radio" name="{{ $field('consensus.scheduler.mode') }}" value="event_driven"
                       x-model="mode"
                       class="text-sky-500 border-gray-700 bg-gray-800 focus:ring-sky-500/30">
                <span class="text-sm text-gray-400">Event-driven <span class="text-[10px] text-amber-400">(phase 3 — à venir)</span></span>
            </label>
        </div>
    </x-admin.section>

    {{-- Cron ─────────────────────────────────────────────────────── --}}
    <div x-show="mode === 'cron'" class="mb-6">
        <x-admin.section title="Configuration cron" icon="fa-solid fa-stopwatch" color="gray">
            @php $k = 'consensus.scheduler.cron_minute'; $s = $schedulerSettings[$k]; @endphp
            <x-admin.input name="{{ $field($k) }}" label="{{ $s['label'] }}" type="number"
                           min="0" max="59" step="1" required class="font-mono"
                           :value="old($field($k), $s['value'])" suffix="min"
                           hint="{{ $s['description'] }}" wrapperClass="w-48" />
        </x-admin.section>
    </div>

    {{-- Event-driven ─────────────────────────────────────────────── --}}
    <div x-show="mode === 'event_driven'" x-cloak class="mb-6">
        <x-admin.section title="Configuration event-driven" icon="fa-solid fa-bolt" color="amber">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
                @foreach (['consensus.scheduler.event_debounce_seconds', 'consensus.scheduler.safety_net_hours'] as $k)
                    @php $s = $schedulerSettings[$k]; @endphp
                    <x-admin.input name="{{ $field($k) }}" label="{{ $s['label'] }}" type="number"
                                   min="0" step="1" required class="font-mono"
                                   :value="old($field($k), $s['value'])"
                                   suffix="{{ str_contains($k, 'seconds') ? 's' : 'h' }}"
                                   hint="{{ $s['description'] }}" />
                @endforeach
            </div>
        </x-admin.section>
    </div>

    {{-- Priorités par horizon ────────────────────────────────────── --}}
    <x-admin.section title="Priorités par horizon" icon="fa-solid fa-ranking-star" color="violet" class="mb-6">
        <p class="text-xs text-gray-500 mb-4">Priorité relative du calcul consensus par jour d'horizon (0-100). Le scheduler traitera les horizons prioritaires en premier.</p>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-x-6 gap-y-3">
            @foreach (['consensus.scheduler.priority_horizon_J', 'consensus.scheduler.priority_horizon_J1', 'consensus.scheduler.priority_horizon_J2', 'consensus.scheduler.priority_horizon_J3', 'consensus.scheduler.priority_horizon_J4'] as $k)
                @php
                    $s = $schedulerSettings[$k];
                    $dayLabel = str_replace('Priorité ', '', $s['label']);
                @endphp
                <x-admin.input name="{{ $field($k) }}" label="{{ $dayLabel }}" type="number"
                               min="0" max="100" step="1" required class="font-mono"
                               :value="old($field($k), $s['value'])"
                               hint="{{ $s['description'] }}" />
            @endforeach
        </div>
    </x-admin.section>

    {{-- Priorité variables dérivées ──────────────────────────────── --}}
    <x-admin.section title="Variables dérivées" icon="fa-solid fa-diagram-project" color="gray" class="mb-6">
        @php $k = 'consensus.scheduler.priority_derived'; $s = $schedulerSettings[$k]; @endphp
        <x-admin.input name="{{ $field($k) }}" label="{{ $s['label'] }}" type="number"
                       min="0" max="100" step="1" required class="font-mono"
                       :value="old($field($k), $s['value'])"
                       hint="{{ $s['description'] }}" wrapperClass="w-48" />
    </x-admin.section>

    <div class="flex items-center justify-between">
        <x-admin.button type="submit" variant="primary" icon="fa-solid fa-floppy-disk">
            Enregistrer l'orchestration
        </x-admin.button>
    </div>
</form>

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush
