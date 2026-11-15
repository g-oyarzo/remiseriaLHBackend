<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);
        $middleware->statefulApi();
        // Seguridad (SEC-16, hallado con OWASP ZAP): una request a la API sin
        // token y sin "Accept: application/json" respondía 500, porque el
        // middleware intentaba redirigir a una ruta "login" inexistente. La
        // API no redirige: responde 401 en JSON.
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
        // Límite general por usuario/IP para toda la API (ver
        // config('remiseria.rate_limits') y AppServiceProvider).
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();