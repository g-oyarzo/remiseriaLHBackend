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

    /*
    | Servicio externo de ruteo usado por el cotizador (CU 02).
    |
    | driver: "osrm" (OpenStreetMap, servidor propio o público), "google"
    | (Google Maps Routes API) o "ninguno". Por defecto es "ninguno": sin
    | configuración explícita no se envían coordenadas de pasajeros a ningún
    | tercero y el cotizador usa directamente la contingencia por zonas.
    */
    'mapas' => [
        'driver' => env('MAPAS_DRIVER', 'ninguno'),
        'timeout_segundos' => (float) env('MAPAS_TIMEOUT_SEGUNDOS', 3),
        'reintentos' => (int) env('MAPAS_REINTENTOS', 1),
        'cache_minutos' => (int) env('MAPAS_CACHE_MINUTOS', 10),
        'osrm' => [
            'url' => env('MAPAS_OSRM_URL'),
        ],
        'google' => [
            'api_key' => env('GOOGLE_MAPS_API_KEY'),
            'url' => env('GOOGLE_ROUTES_URL', 'https://routes.googleapis.com/directions/v2:computeRoutes'),
        ],
    ],

];
