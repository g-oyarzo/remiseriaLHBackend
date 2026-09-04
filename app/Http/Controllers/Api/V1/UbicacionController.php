<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Events\UbicacionConductorActualizada;
use App\Http\Controllers\Controller;
use App\Models\Conductor;
use App\Models\Viaje;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\SimpleMessageResponse;
use App\OpenApi\Schemas\UpdateUbicacionRequest as UpdateUbicacionRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\ValueObjects\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class UbicacionController extends Controller
{
    /**
     * PATCH /api/v1/conductor/ubicacion
     *
     * Actualiza la ubicación actual (GPS) del conductor (RNF02).
     */
    #[OA\Patch(
        path: '/conductor/ubicacion',
        summary: 'Actualizar ubicación GPS del conductor',
        description: 'Persiste la nueva posición y emite en cola el evento UbicacionConductorActualizada por WebSockets (Reverb) en el canal privado conductor.{conductorId} y, si el conductor tiene un viaje "aceptado" o "en_curso", también en viaje.{viajeId}.',
        tags: ['Conductor'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: UpdateUbicacionRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Ubicación actualizada.', content: new OA\JsonContent(ref: SimpleMessageResponse::class)),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "conductor".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'No existe un registro de Conductor para la cuenta autenticada.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $cuenta = $request->user();

        if ($cuenta->rol !== RolPersona::Conductor) {
            return response()->json([
                'message' => 'Solo los conductores pueden actualizar su ubicación.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $conductor = Conductor::query()->find($cuenta->persona_id);

        if (! $conductor) {
            return response()->json([
                'message' => 'Conductor no encontrado.',
            ], Response::HTTP_NOT_FOUND);
        }

        $ubicacion = new Coordinate(
            lat: (float) $validated['lat'],
            lng: (float) $validated['lng']
        );

        $conductor->update(['ubicacion_actual' => $ubicacion]);

        $viajeActivoId = Viaje::query()
            ->where('conductor_id', $conductor->persona_id)
            ->whereIn('estado', [EstadoViaje::Aceptado, EstadoViaje::EnCurso])
            ->value('id');

        UbicacionConductorActualizada::dispatch(
            conductorId: $conductor->persona_id,
            ubicacion: $ubicacion,
            viajeActivoId: $viajeActivoId,
        );

        return response()->json([
            'message' => 'Ubicación actualizada correctamente.',
        ]);
    }
}