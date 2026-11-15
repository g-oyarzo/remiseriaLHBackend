<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seguridad (SEC-08): la documentación OpenAPI (/api/documentation y
 * /docs) expone el mapa completo de la API, con roles, parámetros y
 * contratos de pago. En producción queda deshabilitada (404) salvo que se
 * active explícitamente con API_DOCS_PUBLICA=true.
 */
class DocumentacionApiHabilitada
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('remiseria.documentacion_api_publica'), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
