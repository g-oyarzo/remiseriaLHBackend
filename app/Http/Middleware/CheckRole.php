<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RolPersona;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de autorización por rol.
 *
 * Uso en rutas:
 *   ->middleware('role:administrador')
 *   ->middleware('role:cliente,conductor')   // cualquiera de los dos
 *
 * Requiere que la request ya haya pasado por auth:sanctum para que
 * $request->user() devuelva la Cuenta autenticada.
 */
class CheckRole
{
    /**
     * @param  string  ...$roles  Uno o más valores de RolPersona separados por coma en la definición de la ruta.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $cuenta = $request->user();

        if (! $cuenta) {
            return response()->json([
                'message' => 'No autenticado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $rolesPermitidos = array_map(
            fn (string $rol) => RolPersona::tryFrom($rol),
            $roles
        );

        if (! in_array($cuenta->rol, $rolesPermitidos, true)) {
            return response()->json([
                'message' => 'No tiene permisos para acceder a este recurso.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
