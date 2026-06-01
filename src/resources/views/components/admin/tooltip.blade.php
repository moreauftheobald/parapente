@props([
    'text' => null,
])

{{--
    Icône « ? » avec infobulle au survol — utilisée à côté des labels
    pour afficher la description/aide d'un champ sans encombrer l'écran.

    Usage :
      <x-admin.tooltip text="Consensus de précipitations au-delà duquel..." />
--}}

@if ($text)
    <span class="relative group/tip inline-flex">
        <i class="fa-solid fa-circle-question text-gray-600 hover:text-gray-400 cursor-help text-[10px]"></i>
        <span class="invisible opacity-0 group-hover/tip:visible group-hover/tip:opacity-100
                      absolute bottom-full left-1/2 -translate-x-1/2 mb-2
                      w-64 px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg
                      text-xs text-gray-300 leading-relaxed shadow-lg
                      z-50 pointer-events-none transition-opacity duration-150">
            {{ $text }}
            <span class="absolute top-full left-1/2 -translate-x-1/2 -mt-px
                         border-4 border-transparent border-t-gray-700"></span>
        </span>
    </span>
@endif
