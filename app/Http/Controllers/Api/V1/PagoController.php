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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Attributes as OA;
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

        if ($viaje->estado !== EstadoViaje::Finalizado) {
            return response()->json([
                'message' => 'Solo se puede registrar un pago para viajes finalizados.',
            ], Response::HTTP_CONFLICT);
        }

        if ($viaje->pago()->exists()) {
            return response()->json([
                'message' => 'El viaje ya tiene un pago registrado.',
            ], Response::HTTP_CONFLICT);
        }

        $validated = $request->validate([
            'metodo_pago' => ['required', new Enum(MetodoPago::class)],
            'monto' => ['required', 'numeric', 'min:0'],
        ]);

        $pago = Pago::query()->create([
            'viaje_id' => $viaje->id,
            'metodo_pago' => $validated['metodo_pago'],
            'monto' => $validated['monto'],
            'estado' => EstadoPago::Confirmado,
        ]);

        return response()->json([
            'message' => 'Pago registrado exitosamente.',
            'data' => $pago,
        ], Response::HTTP_CREATED);
    }
}