<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'consensus_grid' => [
        // Sidecar V1 — calcul du consensus météo (grille pré-calculée
        // toutes les heures, exposée en HTTP). Sert la carte météo
        // et le fetch consensus. Voir la doc du conteneur
        // `consensus-grid` du docker-compose.
        // - dev local : http://localhost:8082
        // - prod docker-compose : http://consensus-grid:8082
        'base_url' => env('CONSENSUS_GRID_BASE_URL', 'http://consensus-grid:8082'),
        'timeout'  => (int) env('CONSENSUS_GRID_TIMEOUT', 10),
    ],

    'consensus_grid_v2' => [
        // Sidecar V2 — expose les endpoints d'introspection admin
        // (/v1/consensus/active_config, /v1/consensus/dependency_graph,
        // /v1/models/variables). Cohabite avec le V1 pendant la migration.
        // - dev local : http://localhost:8083
        // - prod docker-compose : http://consensus-grid-v2:8083
        'base_url' => env('CONSENSUS_GRID_V2_BASE_URL', 'http://parapente-consensus-grid-v2-api:8083'),
        'timeout'  => (int) env('CONSENSUS_GRID_V2_TIMEOUT', 10),
    ],

    'geocoding' => [
        'geo_api_gouv' => [
            'base_url' => env('GEO_API_GOUV_BASE_URL', 'https://geo.api.gouv.fr'),
            'timeout'  => (int) env('GEO_API_GOUV_TIMEOUT', 15),
        ],
        'nominatim' => [
            'base_url'           => env('NOMINATIM_BASE_URL', 'https://nominatim.openstreetmap.org'),
            'user_agent'         => env('NOMINATIM_USER_AGENT', 'qui-vole.fr'),
            'contact_email'      => env('NOMINATIM_CONTACT_EMAIL', ''),
            'timeout'            => (int) env('NOMINATIM_TIMEOUT', 15),
            'rate_limit_seconds' => (int) env('NOMINATIM_RATE_LIMIT', 1),
        ],
    ],

];
