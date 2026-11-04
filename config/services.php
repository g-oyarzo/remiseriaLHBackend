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
    | Mercado Pago (Checkout Pro + webhooks) para cobros digitales.
    |
    | - access_token: credencial privada de la aplicación (TEST-... en
    |   sandbox, APP_USR-... en producción).
    | - webhook_secret: clave secreta de Webhooks (Tus integraciones >
    |   Webhooks) para validar la firma x-signature.
    | - notification_url: URL pública HTTPS de POST /api/v1/webhooks/mercadopago.
    | - sandbox: usar sandbox_init_point en lugar de init_point.
    */
    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'notification_url' => env('MERCADOPAGO_NOTIFICATION_URL'),
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
        'sandbox' => (bool) env('MERCADOPAGO_SANDBOX', true),
        'timeout_segundos' => (float) env('MERCADOPAGO_TIMEOUT_SEGUNDOS', 10),
        'preferencia_vigencia_minutos' => (int) env('MERCADOPAGO_PREFERENCIA_VIGENCIA_MINUTOS', 30),
    ],

    /*
    | Servicio de clima para el recargo dinámico por lluvia.
    |
    | driver: "openmeteo" o "ninguno" (por defecto: sin recargo por clima).
    | La API pública de Open-Meteo es solo para uso no comercial; en
    | producción usar un plan con API key (customer-api.open-meteo.com).
    */
    'clima' => [
        'driver' => env('CLIMA_DRIVER', 'ninguno'),
        'timeout_segundos' => (float) env('CLIMA_TIMEOUT_SEGUNDOS', 2),
        'cache_minutos' => (int) env('CLIMA_CACHE_MINUTOS', 10),
        'openmeteo' => [
            'url' => env('CLIMA_OPENMETEO_URL', 'https://api.open-meteo.com/v1/forecast'),
            'api_key' => env('CLIMA_OPENMETEO_API_KEY'),
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
