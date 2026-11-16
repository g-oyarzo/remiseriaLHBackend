<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoConductor;
use App\Enums\RolPersona;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarUbicacionRequest;
use App\Models\Conductor;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\UpdateUbicacionRequest as UpdateUbicacionRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\Services\Geolocalizacion\RegistroDeUbicaciones;
use App\Services\Geolocalizacion\UbicacionInconsistente;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class UbicacionController extends Controller
{
    /**
     * POST|PATCH /api/v1/conductor/ubicacion
     *
     * Ping GPS del conductor autenticado (RNF02). La posición siempre se
     * asocia al conductor del token: no se puede informar la de otro.
     */
    #[OA\Post(
        path: '/conductor/ubicacion',
        summary: 'Informar la ubicación GPS del conductor',
        description: 'Guarda el último punto en Redis (respaldo en MySQL), suma el tramo al recorrido si hay un viaje en curso y difunde "ubicacion.actualizada" por WebSocket. Rechaza con 422 un punto que implica una velocidad imposible respecto del anterior. También se acepta PATCH por compatibilidad. Límite de frecuencia: RATE_LIMIT_UBICACION por minuto.',
        tags: ['Conductores'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: UpdateUbicacionRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Ubicación registrada.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Ubicación actualizada correctamente.'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'lat', type: 'number', format: 'float', example: -34.9214),
                    new OA\Property(property: 'lng', type: 'number', format: 'float', example: -57.9544),
                    new OA\Property(property: 'registrado_en', type: 'string', format: 'date-time'),
                ]),
            ])),
            new OA\Response(response: 403, description: 'La cuenta no es conductor o el conductor está dado de baja.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Coordenadas inválidas o inconsistentes con el ping anterior.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
            new OA\Response(response: 429, description: 'Demasiados pings en poco tiempo.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function update(ActualizarUbicacionRequest $request, RegistroDeUbicaciones $registro): JsonResponse
    {
        $cuenta = $request->user();

        if ($cuenta->rol !== RolPersona::Conductor) {
            return response()->json([
                'message' => 'Solo los conductores pueden actualizar su ubicación.',
            ], Response::HTTP_FORBIDDEN);
        }

        $conductor = Conductor::query()->find($cuenta->persona_id);

        if (! $conductor) {
            return response()->json([
                'message' => 'Conductor no encontrado.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($conductor->estado === EstadoConductor::Eliminado) {
            return response()->json([
                'message' => 'El conductor está dado de baja.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $ubicacion = $registro->registrar($conductor, $request->coordenada(), $request->precision());
        } catch (UbicacionInconsistente $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['lat' => [$e->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Ubicación actualizada correctamente.',
            'data' => $ubicacion->coordenada->toArray() + [
                'registrado_en' => $ubicacion->registradaEn->toIso8601String(),
            ],
        ]);
    }
}
