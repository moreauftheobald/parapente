<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Palette des modèles météo
    |--------------------------------------------------------------------------
    |
    | Couleur d'affichage de chaque modèle NWP dans l'onglet « Modèles 5 j »
    | du volet droit de la carte. Code modèle Open-Meteo → couleur hex.
    | Les modèles non listés tombent sur le gris neutre (cf. fallback dans
    | l'appelant : `config('weather.model_colors.fallback')`).
    |
    */

    'model_colors' => [
        'meteofrance_arome_france'   => '#ef4444',
        'icon_d2'                    => '#a855f7',
        'knmi_harmonie_arome_europe' => '#06b6d4',
        'meteofrance_arpege_europe'  => '#f59e0b',
        'icon_eu'                    => '#3b82f6',
        'ecmwf_ifs025'               => '#8b5cf6',
        'ecmwf_aifs025'              => '#ec4899',
        'icon_seamless'              => '#84cc16',
        'gem_seamless'               => '#10b981',
        'gfs_seamless'               => '#f97316',
        'qui_vole_consensus'         => '#0f172a',
        'fallback'                   => '#9ca3af',
    ],

];
