<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Http\Controllers\Controller;
use App\Models\Pago;
use App\Models\Viaje;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PagoSchema;
use App\OpenApi\Schemas\RegistrarPagoRequest as RegistrarPagoRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Attributes as OA;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class PagoController extends Controller
{
    /**
     * POST /api/v1/viajes/{viaje}/pago
     *
     * Registra un pago para un viaje finalizado.
     */
    #[OA\Post(
        path: '/conductor/viajes/{viaje}/pago',
        summary: 'Registrar el pago de un viaje',
        description: 'Solo el conductor asignado al viaje o un administrador. El viaje debe estar "finalizado" y no tener un pago previo (relación 1 a 1).',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: RegistrarPagoRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Pago registrado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Pago registrado exitosamente.'),
                new OA\Property(property: 'data', ref: PagoSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada es un cliente, o un conductor no asignado a este viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está finalizado, o ya tiene un pago registrado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(Request $request, Viaje $viaje): JsonResponse
    {
        $cuenta = $request->user();

        // Admin o el conductor del viaje pueden registrar el pago.
        if ($cuenta->rol === RolPersona::Cliente ||
            ($cuenta->rol === RolPersona::Conductor && $viaje->conductor_id !== $cuenta->persona_id)) {
            return response()->json([
                'message' => 'No tiene permisos para registrar un pago para este viaje.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'metodo_pago' => ['required', new Enum(MetodoPago::class)],
            'monto' => ['required', 'numeric', 'min:0'],
        ]);

        // Corrección de auditoría (HALL-003): antes se comprobaba
        // "¿ya existe un pago?" y se creaba el registro en dos pasos sin
        // ningún lock, por lo que dos requests simultáneas (doble tap del
        // conductor, reintento de red, etc.) podían pasar ambas la
        // verificación antes de que cualquiera insertara el pago. La tabla
        // tiene un unique(viaje_id) como red de seguridad a nivel de BD,
        // pero eso solo evitaba el duplicado con un 500 no controlado.
        //
        // Ahora todo el ciclo lectura+escritura ocurre dentro de una única
        // transacción con SELECT ... FOR UPDATE sobre la fila del viaje:
        // la segunda request queda bloqueada hasta que la primera confirma,
        // y al reanudar vuelve a leer el estado ya actualizado (con el pago
        // recién creado), por lo que su propia verificación de "¿ya tiene
        // pago?" ahora sí lo detecta y devuelve 409 en lugar de duplicar.
        try {
            $pago = DB::transaction(function () use ($viaje, $validated): Pago {
                $viajeActual = Viaje::query()->lockForUpdate()->findOrFail($viaje->id);

                if ($viajeActual->estado !== EstadoViaje::Finalizado) {
                    throw new RuntimeException('Solo se puede registrar un pago para viajes finalizados.');
                }

                if ($viajeActual->pago()->exists()) {
                    throw new RuntimeException('El viaje ya tiene un pago registrado.');
                }

                return Pago::query()->create([
                    'viaje_id' => $viajeActual->id,
                    'metodo_pago' => $validated['metodo_pago'],
                    'monto' => $validated['monto'],
                    'estado' => EstadoPago::Confirmado,
                    // HALL-008: se fija explícitamente en el momento de la
                    // confirmación en lugar de depender del useCurrent() de
                    // la migración (ver 2025_06_01_000100_create_pagos_table).
                    'fecha_pago' => now(),
                ]);
            });
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Viaje inexistente.',
            ], Response::HTTP_NOT_FOUND);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'message' => 'Pago registrado exitosamente.',
            'data' => $pago,
        ], Response::HTTP_CREATED);
    }
}