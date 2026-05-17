@props([
    'status' => null,   // published | draft | active | inactive | success | warning | danger | info | neutral
    'icon'   => null,   // classes FA optionnelles (ex: 'fa-solid fa-thumbtack')
    'dot'    => true,   // point coloré devant le label (par défaut oui)
])

{{--
    Badge de statut compact et cohérent (publié/brouillon/actif/inactif/etc.).
    Remplace les 3 styles concurrents qui coexistent (point+texte / icône FA /
    pilule colorée).

    Usage :
      <x-admin.badge status="published">Publié</x-admin.badge>
      <x-admin.badge status="draft">Brouillon</x-admin.badge>
      <x-admin.badge status="active" :dot="false" icon="fa-solid fa-circle-check">Actif</x-admin.badge>
      <x-admin.badge status="info">Admin</x-admin.badge>
--}}

@php
    $palette = match ($status) {
        'published', 'active', 'success' => ['text' => 'text-emerald-400', 'bg' => 'bg-emerald-500/10', 'border' => 'border-emerald-500/30', 'dot' => 'bg-emerald-400'],
        'warning'                        => ['text' => 'text-amber-300',  'bg' => 'bg-amber-500/10',  'border' => 'border-amber-500/30',  'dot' => 'bg-amber-400'],
        'danger', 'error'                => ['text' => 'text-red-300',    'bg' => 'bg-red-500/10',    'border' => 'border-red-500/30',    'dot' => 'bg-red-400'],
        'info'                           => ['text' => 'text-sky-300',    'bg' => 'bg-sky-500/10',    'border' => 'border-sky-500/30',    'dot' => 'bg-sky-400'],
        'admin', 'role-admin'            => ['text' => 'text-violet-300', 'bg' => 'bg-violet-500/15', 'border' => 'border-violet-500/30', 'dot' => 'bg-violet-400'],
        default                          => ['text' => 'text-gray-500',   'bg' => 'bg-gray-800/40',   'border' => 'border-gray-700',      'dot' => 'bg-gray-500'],
    };

    $cls = 'inline-flex items-center gap-1.5 text-xs font-medium ' . $palette['text'];
@endphp

<span {{ $attributes->merge(['class' => $cls]) }}>
    @if ($icon)
        <i class="{{ $icon }}"></i>
    @elseif ($dot)
        <span class="w-1.5 h-1.5 rounded-full {{ $palette['dot'] }}"></span>
    @endif
    {{ $slot }}
</span>
