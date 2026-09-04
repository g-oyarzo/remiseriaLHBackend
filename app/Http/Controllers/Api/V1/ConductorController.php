<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoConductor;
use App\Http\Controllers\Controller;
use App\Models\Conductor;
use App\OpenApi\Schemas\ConductorSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\ToggleServicioRequest as ToggleServicioRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\ValueObjects\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class ConductorController extends Controller
{
    /**
     * GET /api/v1/admin/conductores
     *
     * Listado para administradores.
     */
    #[OA\Get(
        path: '/admin/conductores',
        summary: 'Listar conductores',
        tags: ['Admin - Conductores'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'estado', in: 'query', required: false, schema: new OA\Schema(ref: EstadoConductor::class)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK.',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: PaginationMeta::class),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: ConductorSchema::class)),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $query = Conductor::query()->with(['persona.cuenta', 'vehiculo.marca']);

        if ($request->has('estado')) {
            $estado = EstadoConductor::tryFrom($request->input('estado'));
            if ($estado) {
                $query->where('estado', $estado);
            }
        }

        $conductores = $query->paginate((int) $request->input('per_page', 15));

        return response()->json($conductores);
    }

    /**
     * GET /api/v1/admin/conductores/{conductor}
     */
    #[OA\Get(
        path: '/admin/conductores/{conductor}',
        summary: 'Detalle de un conductor',
        description: 'Incluye sus últimos 5 viajes (por fecha_viaje).',
        tags: ['Admin - Conductores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conductor', in: 'path', required: true, description: 'persona_id del conductor.', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: ConductorSchema::class)])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Conductor inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function show(Conductor $conductor): JsonResponse
    {
        $conductor->load(['persona.cuenta', 'vehiculo.marca', 'viajes' => function ($query) {
            $query->latest('fecha_viaje')->limit(5);
        }]);

        return response()->json(['data' => $conductor]);
    }

    /**
     * GET /api/v1/conductores/cercanos
     *
     * Busca conductores activos y en servicio cerca de una ubicación (para despachador).
     */
    public function cercanos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radio_metros' => ['nullable', 'numeric', 'min:100', 'max:50000'],
        ]);

        $origen = new Coordinate(
            lat: (float) $validated['lat'],
            lng: (float) $validated['lng']
        );

        $radio = isset($validated['radio_metros']) ? (float) $validated['radio_metros'] : 5000.0;

        $conductores = Conductor::query()
            ->with(['persona', 'vehiculo.marca'])
            ->disponibles()
            ->cercanos($origen, $radio)
            ->get();

        return response()->json(['data' => $conductores]);
    }

    /**
     * PATCH /api/v1/conductor/estado-servicio
     *
     * El propio conductor alterna su estado de en_servicio.
     */
    #[OA\Patch(
        path: '/conductor/estado-servicio',
        summary: 'Alternar disponibilidad de servicio',
        description: 'El propio conductor autenticado marca en_servicio = true/false para empezar o dejar de recibir despachos.',
        tags: ['Conductor'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: ToggleServicioRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Estado de servicio actualizado.'),
                new OA\Property(property: 'data', ref: ConductorSchema::class),
            ])),
            new OA\Response(response: 403, description: 'El legajo del conductor está eliminado (baja lógica).', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'No existe un registro de Conductor para la cuenta autenticada.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function toggleServicio(Request $request): JsonResponse
    {
        $cuenta = $request->user();
        
        $conductor = Conductor::query()->findOrFail($cuenta->persona_id);

        if ($conductor->estado === EstadoConductor::Eliminado) {
            return response()->json([
                'message' => 'No puede ponerse en servicio porque su cuenta está eliminada.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'en_servicio' => ['required', 'boolean'],
        ]);

        $conductor->update(['en_servicio' => $validated['en_servicio']]);

        return response()->json([
            'message' => 'Estado de servicio actualizado.',
            'data' => $conductor,
        ]);
    }
}