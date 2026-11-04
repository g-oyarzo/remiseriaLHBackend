<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CotizarViajeRequest;
use App\OpenApi\Schemas\CotizacionSchema;
use App\OpenApi\Schemas\CotizarViajeRequestSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\Services\Cotizacion\CotizacionesEmitidas;
use App\Services\Cotizacion\CotizacionNoDisponible;
use App\Services\Cotizacion\CotizadorService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class CotizacionController extends Controller
{
    /**
     * POST /api/v1/viajes/cotizar (CU 02)
     */
    #[OA\Post(
        path: '/viajes/cotizar',
        summary: 'Cotizar un viaje',
        description: 'Precio cerrado: devuelve cotizacion_id, vigente hasta vigente_hasta; al solicitar o reservar con ese id se cobra exactamente este monto. Calcula el costo (tarifa base + distancia * precio por km, más el recargo dinámico) con la distancia de manejo del servicio de mapas. Si el servicio falla o no responde a tiempo, aplica la contingencia por zonas (metodo = "zona" o "estimacion_lineal"). Disponible para clientes y administradores.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CotizarViajeRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Cotización.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: CotizacionSchema::class)])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no es cliente ni administrador.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'No hay tarifa vigente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Coordenadas inválidas, origen igual al destino o distancia fuera de rango.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
            new OA\Response(response: 429, description: 'Demasiadas cotizaciones en poco tiempo.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function cotizar(CotizarViajeRequest $request, CotizadorService $cotizador, CotizacionesEmitidas $cotizaciones): JsonResponse
    {
        $request->validate(['reserva' => ['sometimes', 'boolean']]);

        try {
            $cotizacion = $cotizador->cotizar($request->origen(), $request->destino(), esReserva: $request->boolean('reserva'));
        } catch (CotizacionNoDisponible $e) {
            return self::respuestaCotizacionNoDisponible($e);
        }

        $emitida = $cotizaciones->emitir($request->user()->id, $request->origen(), $request->destino(), $request->boolean('reserva'), $cotizacion);

        return response()->json(['data' => [
            'cotizacion_id' => $emitida->id,
            'vigente_hasta' => $emitida->expira_en->toIso8601String(),
        ] + $cotizacion->toArray()]);
    }

    /**
     * 409 si falta configuración (tarifa); 422 si el trayecto es inválido.
     */
    public static function respuestaCotizacionNoDisponible(CotizacionNoDisponible $e): JsonResponse
    {
        return $e->faltaConfiguracion
            ? response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT)
            : response()->json([
                'message' => $e->getMessage(),
                'errors' => ['destino_lat' => [$e->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
