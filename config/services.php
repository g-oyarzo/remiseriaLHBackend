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
    | driver:
    | - "ors": openrouteservice (HeiGIT). Plan Standard gratuito, sin
    |   facturación. Recomendado para empezar (docs/ARQUITECTURA_Y_COSTOS.md).
    | - "google": Google Maps Routes API (SKU Essentials, sin tráfico).
    |   Requiere una cuenta de facturación habilitada en Google Cloud.
    | - "osrm": instancia OSRM propia (alternativa de escalabilidad futura).
    | - "ninguno" (alias "zonas"): sin servicio de mapas; siempre contingencia.
    |
    | Por defecto es "ninguno": sin configuración explícita no se envían
    | coordenadas de pasajeros a ningún tercero.
    |
    | Las cuotas locales cortan las consultas ANTES de llegar al límite del
    | proveedor (0 o vacío = sin límite). Al agotarse, el cotizador usa la
    | contingencia por zonas hasta el período siguiente.
    */
    'mapas' => [
        'driver' => env('MAPAS_DRIVER', 'ninguno'),
        'timeout_segundos' => (float) env('MAPAS_TIMEOUT_SEGUNDOS', 3),
        'reintentos' => (int) env('MAPAS_REINTENTOS', 1),
        'cache_minutos' => (int) env('MAPAS_CACHE_MINUTOS', 10),
        // Store para cuotas y circuit breaker (null = CACHE_STORE). En
        // producción, "redis", para compartir el estado entre procesos.
        'cache_store' => env('MAPAS_CACHE_STORE'),
        // Si el proveedor confirma que no hay recorrido por calles, se
        // rechaza la cotización en lugar de cobrar por zonas.
        'rechazar_sin_ruta' => (bool) env('MAPAS_RECHAZAR_SIN_RUTA', true),
        'circuito' => [
            'umbral_fallas' => (int) env('MAPAS_CIRCUITO_UMBRAL_FALLAS', 3),
            'segundos_abierto' => (int) env('MAPAS_CIRCUITO_SEGUNDOS', 60),
            'segundos_tras_429' => (int) env('MAPAS_CIRCUITO_SEGUNDOS_TRAS_429', 300),
        ],
        'cuotas' => [
            // Plan Standard: 2.000/día y 40/min en Directions (se deja margen).
            'ors' => [
                'por_minuto' => env('MAPAS_ORS_CUOTA_POR_MINUTO', 35),
                'por_dia' => env('MAPAS_ORS_CUOTA_POR_DIA', 1900),
                'por_mes' => env('MAPAS_ORS_CUOTA_POR_MES'),
            ],
            // Compute Routes Essentials: 10.000 gratuitas por mes; luego se
            // factura. El tope mensual evita cargos.
            'google' => [
                'por_minuto' => env('MAPAS_GOOGLE_CUOTA_POR_MINUTO', 60),
                'por_dia' => env('MAPAS_GOOGLE_CUOTA_POR_DIA', 320),
                'por_mes' => env('MAPAS_GOOGLE_CUOTA_POR_MES', 9500),
            ],
            // Instancia propia: sin cuota externa.
            'osrm' => [
                'por_minuto' => env('MAPAS_OSRM_CUOTA_POR_MINUTO'),
                'por_dia' => env('MAPAS_OSRM_CUOTA_POR_DIA'),
                'por_mes' => env('MAPAS_OSRM_CUOTA_POR_MES'),
            ],
        ],
        'ors' => [
            'api_key' => env('ORS_API_KEY'),
            'url' => env('ORS_URL', 'https://api.heigit.org/openrouteservice'),
            'perfil' => env('ORS_PERFIL', 'driving-car'),
        ],
        'osrm' => [
            'url' => env('MAPAS_OSRM_URL'),
        ],
        'google' => [
            'api_key' => env('GOOGLE_MAPS_API_KEY'),
            'url' => env('GOOGLE_ROUTES_URL', 'https://routes.googleapis.com/directions/v2:computeRoutes'),
        ],
    ],

];
