<?php

/*
| Proxies de confianza (lo lee Illuminate\Http\Middleware\TrustProxies).
|
| Detrás de NGINX (compose.prod.yaml) hay que confiar en la subred interna
| de Docker para que $request->ip() sea la IP real del cliente: la usan los
| límites de frecuencia, los logs y el registro del consentimiento de las
| tarjetas guardadas. Usar la subred exacta (TRUSTED_PROXIES=172.30.0.0/24),
| nunca "*" con el puerto de PHP expuesto: cualquiera podría falsear
| X-Forwarded-For. Vacío = no se confía en ningún proxy (desarrollo).
*/

$proxies = env('TRUSTED_PROXIES');

return [
    'proxies' => filled($proxies)
        ? array_values(array_filter(array_map('trim', explode(',', (string) $proxies))))
        : null,
];
