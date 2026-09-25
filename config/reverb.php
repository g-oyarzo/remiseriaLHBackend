<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all of your
    | connected clients. At this time only Reverb's server is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REVERB_SCALING_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
                    'port' => env('REVERB_SCALING_REDIS_PORT', env('REDIS_PORT', '6379')),
                    'username' => env('REVERB_SCALING_REDIS_USERNAME', env('REDIS_USERNAME')),
                    'password' => env('REVERB_SCALING_REDIS_PASSWORD', env('REDIS_PASSWORD')),
                    'database' => env('REVERB_SCALING_REDIS_DB', env('REDIS_DB', '0')),
                    'timeout' => env('REVERB_SCALING_REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your Reverb server will support, including their connection keys.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                // Corrección de auditoría (HALL-023): antes era ['*'] fijo,
                // permitiendo que cualquier dominio abriera conexiones
                // WebSocket. En producción, definir REVERB_ALLOWED_ORIGINS
                // con una lista separada por comas (ej:
                // "https://app.remiserialh.com,https://admin.remiserialh.com").
                // Si no se define, se mantiene '*' para no romper entornos
                // de desarrollo/demo.
                'allowed_origins' => array_filter(array_map(
                    'trim',
                    explode(',', env('REVERB_ALLOWED_ORIGINS', '*'))
                )),
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],

    ],

];