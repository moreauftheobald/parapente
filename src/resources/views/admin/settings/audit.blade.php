@extends('layouts.admin')
@section('title', 'Historique des paramètres')

@section('content')
<div x-data="auditPanel()">
    <x-admin.page-title title="Historique des paramètres">
        <x-slot:subtitle>Audit trail de toutes les modifications de paramètres.</x-slot:subtitle>
        <x-slot:actions>
            <form method="GET" class="flex items-center gap-2">
                <input name="search" type="search" value="{{ request('search') }}" placeholder="Filtrer par clé…"
                       class="px-2.5 py-1.5 text-xs bg-gray-950 border border-gray-700 rounded-md text-gray-100 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500/40 w-48">
                <x-admin.button type="submit" variant="primary" size="sm" icon="fa-solid fa-filter">Filtrer</x-admin.button>
            </form>
        </x-slot:actions>
    </x-admin.page-title>

    @if ($entries->isEmpty())
        <x-admin.empty-state icon="fa-solid fa-clock-rotate-left" message="Aucune modification enregistrée." />
    @else
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-950 text-gray-500 text-xs uppercase tracking-wider border-b border-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium">Date</th>
                        <th class="px-3 py-2 text-left font-medium">Clé</th>
                        <th class="px-3 py-2 text-left font-medium">Ancienne valeur</th>
                        <th class="px-3 py-2 text-left font-medium">Nouvelle valeur</th>
                        <th class="px-3 py-2 text-left font-medium">Utilisateur</th>
                        <th class="px-3 py-2 text-center font-medium w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/70">
                    @foreach ($entries as $entry)
                        @php
                            $isJson = (str_starts_with(trim((string) $entry->old_value), '{') || str_starts_with(trim((string) $entry->old_value), '['))
                                   || (str_starts_with(trim((string) $entry->new_value), '{') || str_starts_with(trim((string) $entry->new_value), '['));
                        @endphp
                        <tr class="hover:bg-gray-800/30 transition">
                            <td class="px-3 py-2 text-xs font-mono text-gray-400 whitespace-nowrap">
                                {{ $entry->changed_at->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-3 py-2 text-xs font-mono text-white">{{ $entry->setting_key }}</td>
                            <td class="px-3 py-2 text-xs font-mono text-gray-500 max-w-48 truncate" title="{{ $entry->old_value }}">
                                {{ Str::limit($entry->old_value, 80) ?: '—' }}
                            </td>
                            <td class="px-3 py-2 text-xs font-mono text-gray-300 max-w-48 truncate" title="{{ $entry->new_value }}">
                                {{ Str::limit($entry->new_value, 80) ?: '—' }}
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-400">
                                {{ $entry->user?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-center">
                                @if ($isJson)
                                    <button type="button"
                                            @click="showDiff(@js($entry->setting_key), @js($entry->old_value), @js($entry->new_value))"
                                            class="text-sky-400 hover:text-sky-300 transition text-xs" title="Voir le diff">
                                        <i class="fa-solid fa-code-compare"></i>
                                    </button>
                                @endif
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

    {{-- Modale diff JSON côte-à-côte --}}
    <template x-teleport="body">
        <div x-show="diffOpen" x-cloak
             @keydown.escape.window="diffOpen = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/60" @click="diffOpen = false"></div>
            <div class="relative bg-gray-900 border border-gray-700 rounded-xl shadow-2xl w-full max-w-4xl max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
                    <h3 class="text-sm font-medium text-white">
                        <i class="fa-solid fa-code-compare text-sky-400 mr-1"></i>
                        Diff — <span class="font-mono text-sky-300" x-text="diffKey"></span>
                    </h3>
                    <button @click="diffOpen = false" class="text-gray-500 hover:text-gray-300 transition">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-0 flex-1 overflow-hidden">
                    <div class="border-r border-gray-800 flex flex-col overflow-hidden">
                        <div class="px-3 py-1.5 bg-red-500/10 border-b border-gray-800 text-xs text-red-400 font-medium shrink-0">
                            <i class="fa-solid fa-minus mr-1"></i> Ancienne valeur
                        </div>
                        <pre class="p-3 text-xs font-mono text-gray-400 overflow-auto flex-1 whitespace-pre-wrap" x-html="diffOldHtml"></pre>
                    </div>
                    <div class="flex flex-col overflow-hidden">
                        <div class="px-3 py-1.5 bg-emerald-500/10 border-b border-gray-800 text-xs text-emerald-400 font-medium shrink-0">
                            <i class="fa-solid fa-plus mr-1"></i> Nouvelle valeur
                        </div>
                        <pre class="p-3 text-xs font-mono text-gray-300 overflow-auto flex-1 whitespace-pre-wrap" x-html="diffNewHtml"></pre>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

@push('styles')
    <style>[x-cloak] { display: none !important; }</style>
@endpush

@push('scripts')
<script>
function auditPanel() {
    return {
        diffOpen: false,
        diffKey: '',
        diffOldHtml: '',
        diffNewHtml: '',

        showDiff(key, oldVal, newVal) {
            this.diffKey = key;
            const oldObj = this.tryParse(oldVal);
            const newObj = this.tryParse(newVal);

            if (oldObj !== null && newObj !== null) {
                this.diffOldHtml = this.renderJsonDiff(oldObj, newObj, 'old');
                this.diffNewHtml = this.renderJsonDiff(oldObj, newObj, 'new');
            } else {
                this.diffOldHtml = this.esc(oldVal || '—');
                this.diffNewHtml = this.esc(newVal || '—');
            }
            this.diffOpen = true;
        },

        tryParse(str) {
            if (!str) return null;
            try { return JSON.parse(str); } catch { return null; }
        },

        esc(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        },

        renderJsonDiff(oldObj, newObj, side) {
            const allKeys = [...new Set([...Object.keys(oldObj), ...Object.keys(newObj)])].sort();
            const lines = ['{'];

            for (const k of allKeys) {
                const inOld = k in oldObj;
                const inNew = k in newObj;
                const oldV = JSON.stringify(oldObj[k]);
                const newV = JSON.stringify(newObj[k]);
                const changed = oldV !== newV;

                let val, cls;
                if (side === 'old') {
                    val = inOld ? oldV : '';
                    if (!inOld) cls = 'opacity-30';
                    else if (!inNew) cls = 'bg-red-500/20 text-red-300';
                    else if (changed) cls = 'bg-amber-500/15 text-amber-300';
                    else cls = '';
                } else {
                    val = inNew ? newV : '';
                    if (!inNew) cls = 'opacity-30';
                    else if (!inOld) cls = 'bg-emerald-500/20 text-emerald-300';
                    else if (changed) cls = 'bg-emerald-500/15 text-emerald-300';
                    else cls = '';
                }

                const line = val ? `  "${this.esc(k)}": ${this.esc(val)}` : `  "${this.esc(k)}": —`;
                lines.push(cls ? `<span class="${cls}">${line}</span>` : line);
            }

            lines.push('}');
            return lines.join('\n');
        },
    };
}
</script>
@endpush
