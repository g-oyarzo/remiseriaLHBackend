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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

class PagoController extends Controller
{
    /**
     * POST /api/v1/viajes/{viaje}/pago
     *
     * Registra un pago para un viaje finalizado.
     */
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
