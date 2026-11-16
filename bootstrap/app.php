<?php

use App\Http\Middleware\CheckRole;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // La API es stateless (tokens Bearer de Sanctum, sin cookies de sesión),
    // por lo que la ruta de autorización de canales privados/presence debe
    // vivir bajo el mismo prefijo y guard que el resto de la API en lugar
    // del grupo de middleware "web" que usa Broadcast::routes() por defecto.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
        ]);
        $middleware->statefulApi();
        // Seguridad (SEC-16, hallado con OWASP ZAP): una request a la API sin
        // token y sin "Accept: application/json" respondía 500, porque el
        // middleware intentaba redirigir a una ruta "login" inexistente. La
        // API no redirige: responde 401 en JSON.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
        // Seguridad (SEC-07): solo se acepta el host de APP_URL. Evita que un
        // encabezado Host manipulado termine en las URL que genera la API
        // (por ejemplo comprobante_url). Laravel no lo aplica en local ni en
        // tests.
        // Host exacto de APP_URL (sin subdominios) más TRUSTED_HOSTS, si se
        // define (lista separada por comas).
        $middleware->trustHosts(at: function (): array {
            $hosts = array_merge(
                [parse_url((string) config('app.url'), PHP_URL_HOST)],
                explode(',', (string) config('app.hosts_de_confianza')),
            );

            return array_values(array_map(
                fn (string $host): string => '^'.preg_quote(trim($host)).'$',
                array_filter($hosts, fn ($host): bool => is_string($host) && trim($host) !== ''),
            ));
        }, subdomains: false);
        // Límite general por usuario/IP para toda la API (ver
        // config('remiseria.rate_limits') y AppServiceProvider).
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Seguridad (SEC-11): si Reverb no responde, el mensaje de la
        // excepción incluye la URL de publicación con auth_key y
        // auth_signature. Se registra sin la query string.
        $exceptions->report(function (BroadcastException $e): void {
            Log::error('No se pudo difundir un evento en tiempo real.', [
                'error' => preg_replace('/(https?:\/\/[^\s?]+)\?\S*/i', '$1?<oculto>', $e->getMessage()),
            ]);
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
