<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Http\Controllers\Controller;
use App\Models\Conductor;
use App\Models\Pago;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\OpenApi\Schemas\CalificarViajeRequest as CalificarViajeRequestSchema;
use App\OpenApi\Schemas\CrearViajeRequest as CrearViajeRequestSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\OpenApi\Schemas\ViajeSchema;
use App\ValueObjects\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class ViajeController extends Controller
{
    /**
     * GET /api/v1/viajes
     *
     * Lista viajes según el rol del usuario autenticado:
     * - Admin: todos (con filtros opcionales)
     * - Cliente: solo los propios
     * - Conductor: solo los asignados
     */
    #[OA\Get(
        path: '/viajes',
        summary: 'Listar viajes',
        description: 'Devuelve los viajes visibles para el usuario autenticado: todos si es administrador, solo los propios si es cliente, o solo los asignados si es conductor.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'estado', in: 'query', required: false, schema: new OA\Schema(ref: EstadoViaje::class)),
            new OA\Parameter(name: 'tipo', in: 'query', required: false, schema: new OA\Schema(ref: TipoViaje::class)),
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
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: ViajeSchema::class)),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'No autenticado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $cuenta = $request->user();
        $query = Viaje::query()->with(['cliente.persona', 'conductor.persona', 'vehiculo', 'tarifa', 'pago']);

        match ($cuenta->rol) {
            RolPersona::Administrador => null, // ve todos
            RolPersona::Cliente => $query->where('cliente_id', $cuenta->persona_id),
            RolPersona::Conductor => $query->where('conductor_id', $cuenta->persona_id),
        };

        if ($request->has('estado')) {
            $estado = EstadoViaje::tryFrom($request->input('estado'));
            if ($estado) {
                $query->where('estado', $estado);
            }
        }

        if ($request->has('tipo')) {
            $tipo = TipoViaje::tryFrom($request->input('tipo'));
            if ($tipo) {
                $query->where('tipo', $tipo);
            }
        }

        $viajes = $query->orderByDesc('fecha_viaje')->paginate(
            perPage: (int) $request->input('per_page', 15),
        );

        return response()->json($viajes);
    }

    /**
     * GET /api/v1/viajes/{viaje}
     *
     * Detalle de un viaje. Protección IDOR: solo el cliente, conductor asignado o admin.
     */
    #[OA\Get(
        path: '/viajes/{viaje}',
        summary: 'Detalle de un viaje',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: ViajeSchema::class)])),
            new OA\Response(response: 401, description: 'No autenticado.', content: new OA\JsonContent(ref: \App\OpenApi\Schemas\ErrorResponse::class)),
            new OA\Response(response: 403, description: 'No tiene rol de cliente.', content: new OA\JsonContent(ref: \App\OpenApi\Schemas\ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: \App\OpenApi\Schemas\ValidationErrorResponse::class)),
            new OA\Response(response: 500, description: 'Error interno del servidor.', content: new OA\JsonContent(ref: \App\OpenApi\Schemas\ServerErrorResponse::class)),
        ],
    )]
    public function show(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorizeAccess($request, $viaje);

        $viaje->load(['cliente.persona', 'conductor.persona', 'vehiculo', 'tarifa', 'pago', 'mensajes']);

        return response()->json(['data' => $viaje]);
    }

    /**
     * POST /api/v1/viajes
     *
     * Solicitar un nuevo viaje (solo clientes).
     */
    #[OA\Post(
        path: '/cliente/viajes',
        summary: 'Solicitar un viaje',
        description: 'Calcula el costo estimado con la tarifa vigente y la distancia en línea recta entre origen y destino (App\ValueObjects\Coordinate::distanciaEnMetrosHacia).',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CrearViajeRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Viaje solicitado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje solicitado exitosamente.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "cliente".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'No hay una tarifa vigente configurada.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $cuenta = $request->user();

        if ($cuenta->rol !== RolPersona::Cliente) {
            return response()->json([
                'message' => 'Solo los clientes pueden solicitar viajes.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'origen_lat' => ['required', 'numeric', 'between:-90,90'],
            'origen_lng' => ['required', 'numeric', 'between:-180,180'],
            'destino_lat' => ['required', 'numeric', 'between:-90,90'],
            'destino_lng' => ['required', 'numeric', 'between:-180,180'],
            'origen_localidad' => ['nullable', 'string', 'max:100'],
            'origen_calle' => ['nullable', 'string', 'max:100'],
            'origen_numero' => ['nullable', 'string', 'max:15'],
            'tipo' => ['sometimes', new Enum(TipoViaje::class)],
            'fecha_viaje' => ['sometimes', 'date', 'after_or_equal:now'],
        ]);

        $tarifa = Tarifa::vigente();

        if (! $tarifa) {
            return response()->json([
                'message' => 'No hay una tarifa vigente configurada.',
            ], Response::HTTP_CONFLICT);
        }

        $origen = new Coordinate(
            lat: (float) $validated['origen_lat'],
            lng: (float) $validated['origen_lng'],
        );

        $destino = new Coordinate(
            lat: (float) $validated['destino_lat'],
            lng: (float) $validated['destino_lng'],
        );

        // Estimación de distancia para cálculo de costo.
        $distanciaKm = $origen->distanciaEnMetrosHacia($destino) / 1000;
        $costoEstimado = $tarifa->calcularCosto($distanciaKm);

        $viaje = Viaje::query()->create([
            'cliente_id' => $cuenta->persona_id,
            'tarifa_id' => $tarifa->id,
            'origen' => $origen,
            'destino' => $destino,
            'origen_localidad' => $validated['origen_localidad'] ?? null,
            'origen_calle' => $validated['origen_calle'] ?? null,
            'origen_numero' => $validated['origen_numero'] ?? null,
            'estado' => EstadoViaje::Solicitado,
            'tipo' => TipoViaje::tryFrom($validated['tipo'] ?? '') ?? TipoViaje::Actual,
            'costo' => $costoEstimado,
            'fecha_viaje' => $validated['fecha_viaje'] ?? now(),
        ]);

        $viaje->load(['cliente.persona', 'tarifa']);

        return response()->json([
            'message' => 'Viaje solicitado exitosamente.',
            'data' => $viaje,
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/viajes/{viaje}/aceptar
     *
     * Un conductor acepta un viaje solicitado. Usa lockForUpdate para evitar
     * doble asignación bajo concurrencia (condición de carrera).
     */
    #[OA\Patch(
        path: '/conductor/viajes/{viaje}/aceptar',
        summary: 'Aceptar un viaje solicitado',
        description: 'Solo conductores en servicio, con estado "activo" y con vehículo asignado. Usa un lock pesimista (SELECT ... FOR UPDATE) para evitar que dos conductores acepten el mismo viaje bajo concurrencia.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Viaje aceptado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje aceptado.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "conductor".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El conductor no está disponible, no tiene vehículo, ya tiene otro viaje activo, o el viaje ya no está disponible.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function aceptar(Request $request, Viaje $viaje): JsonResponse
    {
        $cuenta = $request->user();

        if ($cuenta->rol !== RolPersona::Conductor) {
            return response()->json([
                'message' => 'Solo los conductores pueden aceptar viajes.',
            ], Response::HTTP_FORBIDDEN);
        }

        $conductor = Conductor::query()->find($cuenta->persona_id);

        if (! $conductor || ! $conductor->en_servicio || $conductor->estado->value !== 'activo') {
            return response()->json([
                'message' => 'El conductor no está disponible.',
            ], Response::HTTP_CONFLICT);
        }

        if (! $conductor->vehiculo_id) {
            return response()->json([
                'message' => 'El conductor no tiene un vehículo asignado.',
            ], Response::HTTP_CONFLICT);
        }

        try {
            DB::transaction(function () use ($viaje, $conductor): void {
                // Lock pesimista: re-leer el viaje dentro de la transacción.
                $viajeActual = Viaje::query()->lockForUpdate()->findOrFail($viaje->id);

                if ($viajeActual->estado !== EstadoViaje::Solicitado) {
                    throw new \RuntimeException('El viaje ya no está disponible para ser aceptado.');
                }

                // Verificar que el conductor no tenga otro viaje activo.
                $tieneViajeActivo = Viaje::query()
                    ->where('conductor_id', $conductor->persona_id)
                    ->whereIn('estado', [EstadoViaje::Aceptado, EstadoViaje::EnCurso])
                    ->lockForUpdate()
                    ->exists();

                if ($tieneViajeActivo) {
                    throw new \RuntimeException('El conductor ya tiene un viaje en curso.');
                }

                $viajeActual->update([
                    'conductor_id' => $conductor->persona_id,
                    'vehiculo_id' => $conductor->vehiculo_id,
                    'estado' => EstadoViaje::Aceptado,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        $viaje->refresh()->load(['cliente.persona', 'conductor.persona', 'vehiculo']);

        return response()->json([
            'message' => 'Viaje aceptado.',
            'data' => $viaje,
        ]);
    }

    /**
     * PATCH /api/v1/viajes/{viaje}/iniciar
     *
     * El conductor inicia el viaje (transición aceptado -> en_curso).
     */
    #[OA\Patch(
        path: '/conductor/viajes/{viaje}/iniciar',
        summary: 'Iniciar un viaje aceptado',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Viaje iniciado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje iniciado.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'No tiene acceso a este viaje, o no es el conductor asignado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está en estado "aceptado".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function iniciar(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorizeAccess($request, $viaje);

        if ($viaje->estado !== EstadoViaje::Aceptado) {
            return response()->json([
                'message' => 'El viaje debe estar en estado "aceptado" para iniciarlo.',
            ], Response::HTTP_CONFLICT);
        }

        if ($viaje->conductor_id !== $request->user()->persona_id) {
            return response()->json([
                'message' => 'Solo el conductor asignado puede iniciar el viaje.',
            ], Response::HTTP_FORBIDDEN);
        }

        $viaje->update(['estado' => EstadoViaje::EnCurso]);

        return response()->json([
            'message' => 'Viaje iniciado.',
            'data' => $viaje,
        ]);
    }

    /**
     * PATCH /api/v1/viajes/{viaje}/finalizar
     *
     * El conductor finaliza el viaje.
     */
    #[OA\Patch(
        path: '/conductor/viajes/{viaje}/finalizar',
        summary: 'Finalizar un viaje en curso',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Viaje finalizado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje finalizado.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'No tiene acceso a este viaje, o no es el conductor asignado ni administrador.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está "en curso".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function finalizar(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorizeAccess($request, $viaje);

        if ($viaje->estado !== EstadoViaje::EnCurso) {
            return response()->json([
                'message' => 'El viaje debe estar "en curso" para finalizarlo.',
            ], Response::HTTP_CONFLICT);
        }

        if ($viaje->conductor_id !== $request->user()->persona_id
            && $request->user()->rol !== RolPersona::Administrador) {
            return response()->json([
                'message' => 'Solo el conductor asignado o un administrador puede finalizar el viaje.',
            ], Response::HTTP_FORBIDDEN);
        }

        $viaje->update(['estado' => EstadoViaje::Finalizado]);

        return response()->json([
            'message' => 'Viaje finalizado.',
            'data' => $viaje,
        ]);
    }

    /**
     * PATCH /api/v1/viajes/{viaje}/cancelar
     *
     * Cancela un viaje. Solo se puede cancelar si no ha finalizado.
     */
    #[OA\Patch(
        path: '/viajes/{viaje}/cancelar',
        summary: 'Cancelar un viaje',
        description: 'Solo el cliente que lo solicitó o un administrador; los conductores no pueden cancelar. No se puede cancelar un viaje ya finalizado o ya cancelado.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Viaje cancelado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje cancelado.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'Un conductor intentó cancelar, o el cliente no es dueño del viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje ya finalizó o ya estaba cancelado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function cancelar(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorizeAccess($request, $viaje);

        if ($viaje->estado->esFinal()) {
            return response()->json([
                'message' => 'No se puede cancelar un viaje que ya finalizó o fue cancelado.',
            ], Response::HTTP_CONFLICT);
        }

        // Solo el cliente que lo solicitó o un admin puede cancelar.
        $cuenta = $request->user();
        if ($cuenta->rol === RolPersona::Conductor) {
            return response()->json([
                'message' => 'Los conductores no pueden cancelar viajes. Contacte al administrador.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($cuenta->rol === RolPersona::Cliente && $viaje->cliente_id !== $cuenta->persona_id) {
            return response()->json([
                'message' => 'No puede cancelar un viaje de otro cliente.',
            ], Response::HTTP_FORBIDDEN);
        }

        $viaje->update(['estado' => EstadoViaje::Cancelado]);

        return response()->json([
            'message' => 'Viaje cancelado.',
            'data' => $viaje,
        ]);
    }

    /**
     * PATCH /api/v1/viajes/{viaje}/calificar
     *
     * El cliente califica un viaje finalizado.
     */
    #[OA\Patch(
        path: '/cliente/viajes/{viaje}/calificar',
        summary: 'Calificar un viaje finalizado',
        description: 'Recalcula el promedio de calificación del conductor (Conductor.calificacion) tras registrar la nota.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CalificarViajeRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Viaje calificado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje calificado.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no es el cliente del viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está finalizado, o ya fue calificado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function calificar(Request $request, Viaje $viaje): JsonResponse
    {
        $cuenta = $request->user();

        if ($cuenta->rol !== RolPersona::Cliente || $viaje->cliente_id !== $cuenta->persona_id) {
            return response()->json([
                'message' => 'Solo el cliente del viaje puede calificarlo.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($viaje->estado !== EstadoViaje::Finalizado) {
            return response()->json([
                'message' => 'Solo se puede calificar un viaje finalizado.',
            ], Response::HTTP_CONFLICT);
        }

        if ($viaje->calificacion !== null) {
            return response()->json([
                'message' => 'El viaje ya fue calificado.',
            ], Response::HTTP_CONFLICT);
        }

        $validated = $request->validate([
            'calificacion' => ['required', 'integer', 'between:1,5'],
        ]);

        $viaje->update(['calificacion' => $validated['calificacion']]);

        // Actualizar calificación promedio del conductor.
        if ($viaje->conductor_id) {
            $promedio = Viaje::query()
                ->where('conductor_id', $viaje->conductor_id)
                ->whereNotNull('calificacion')
                ->avg('calificacion');

            Conductor::query()
                ->where('persona_id', $viaje->conductor_id)
                ->update(['calificacion' => round((float) $promedio, 2)]);
        }

        return response()->json([
            'message' => 'Viaje calificado.',
            'data' => $viaje,
        ]);
    }

    /**
     * GET /api/v1/viajes/pendientes
     *
     * Viajes solicitados pendientes de asignación (para conductores y admin).
     */
    #[OA\Get(
        path: '/conductor/viajes/pendientes',
        summary: 'Listar viajes pendientes de asignación',
        description: 'Viajes en estado "solicitado", ordenados por fecha_viaje ascendente, para que un conductor los tome con aceptar().',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK.',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: PaginationMeta::class),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: ViajeSchema::class)),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "conductor".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function pendientes(Request $request): JsonResponse
    {
        $viajes = Viaje::query()
            ->with(['cliente.persona', 'tarifa'])
            ->pendientes()
            ->orderBy('fecha_viaje')
            ->paginate(15);

        return response()->json($viajes);
    }

    /**
     * Verifica que el usuario tenga acceso a un viaje específico (protección IDOR).
     */
    private function authorizeAccess(Request $request, Viaje $viaje): void
    {
        $cuenta = $request->user();

        $autorizado = match ($cuenta->rol) {
            RolPersona::Administrador => true,
            RolPersona::Cliente => $viaje->cliente_id === $cuenta->persona_id,
            RolPersona::Conductor => $viaje->conductor_id === $cuenta->persona_id,
        };

        if (! $autorizado) {
            abort(Response::HTTP_FORBIDDEN, 'No tiene acceso a este viaje.');
        }
    }
}