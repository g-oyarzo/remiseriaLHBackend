<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReservarViajeRequest;
use App\Http\Requests\SolicitarViajeRequest;
use App\Http\Resources\ViajeResource;
use App\Models\Conductor;
use App\Models\Viaje;
use App\OpenApi\Schemas\CalificarViajeRequest as CalificarViajeRequestSchema;
use App\OpenApi\Schemas\CrearViajeRequest as CrearViajeRequestSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\ServerErrorResponse;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\OpenApi\Schemas\ViajeSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Entre 1 y 100.', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
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
            perPage: $this->porPagina($request),
        );

        return response()->json(ViajeResource::paginado($viajes, $request));
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
            new OA\Response(response: 401, description: 'No autenticado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 403, description: 'No tiene rol de cliente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
            new OA\Response(response: 500, description: 'Error interno del servidor.', content: new OA\JsonContent(ref: ServerErrorResponse::class)),
        ],
    )]
    public function show(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorize('ver', $viaje);

        // Corrección de auditoría (sección 7, rendimiento): antes se
        // cargaban TODOS los mensajes del viaje sin límite. Con un chat
        // activo de un viaje largo, o simplemente con el tiempo, esto crece
        // sin cota. Se muestran acá solo los últimos 30 como vista previa;
        // el historial completo y paginado está en
        // GET /viajes/{viaje}/mensajes (MensajeController::index()).
        $viaje->load([
            'cliente.persona',
            'conductor.persona',
            'vehiculo',
            'tarifa',
            'pago',
            'mensajes' => fn ($query) => $query->latest()->limit(30),
        ]);

        return response()->json(['data' => ViajeResource::arreglo($viaje, $request)]);
    }

    /**
     * POST /api/v1/cliente/viajes (compatibilidad)
     *
     * Endpoint original de solicitud. Delega en el mismo flujo que
     * /cliente/viajes/solicitar y /cliente/viajes/reservar (cotización en el
     * servidor, snapshot y despacho), para no mantener dos implementaciones.
     */
    #[OA\Post(
        path: '/cliente/viajes',
        summary: 'Solicitar o reservar un viaje (compatibilidad)',
        description: 'Endpoint anterior, conservado por compatibilidad. Con tipo = "programado" equivale a POST /cliente/viajes/reservar; en otro caso a POST /cliente/viajes/solicitar. Si se omite metodo_pago se asume "efectivo".',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CrearViajeRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Viaje solicitado o reservado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje solicitado exitosamente.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "cliente".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'No hay una tarifa vigente configurada.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(Request $request, SolicitudViajeController $solicitudes): JsonResponse
    {
        if ($request->user()->rol !== RolPersona::Cliente) {
            return response()->json([
                'message' => 'Solo los clientes pueden solicitar viajes.',
            ], Response::HTTP_FORBIDDEN);
        }

        $request->mergeIfMissing(['metodo_pago' => MetodoPago::Efectivo->value]);

        // Resolver el FormRequest desde el contenedor lo valida contra la
        // request actual.
        return $request->input('tipo') === TipoViaje::Programado->value
            ? $solicitudes->reservar(app(ReservarViajeRequest::class))
            : $solicitudes->solicitar(app(SolicitarViajeRequest::class));
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
            'data' => ViajeResource::arreglo($viaje, $request),
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
        // Corrección de auditoría (HALL-005): antes se llamaba primero a
        // authorizeAccess() (que para un cliente devuelve true si es el
        // dueño del viaje) y solo después se verificaba que quien hace la
        // request sea el conductor asignado. La ruta ya está protegida por
        // el middleware role:conductor (routes/api.php), pero si ese
        // middleware llegara a faltar por un error de configuración futuro,
        // el orden anterior dejaba a authorizeAccess() como única barrera,
        // y esta sí deja pasar al cliente dueño del viaje. Ahora el rol se
        // verifica primero, de forma explícita e independiente del
        // middleware (defensa en profundidad).
        if ($request->user()->rol !== RolPersona::Conductor) {
            return response()->json([
                'message' => 'Solo conductores pueden iniciar viajes.',
            ], Response::HTTP_FORBIDDEN);
        }

        $this->authorize('ver', $viaje);

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
            'data' => ViajeResource::arreglo($viaje, $request),
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
        $this->authorize('ver', $viaje);

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
            'data' => ViajeResource::arreglo($viaje, $request),
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
        description: 'Solo el cliente que lo solicitó o un administrador; los conductores no pueden cancelar. El cliente puede cancelar reservas, solicitudes y viajes aceptados; un viaje en curso solo puede cancelarlo un administrador. No se puede cancelar un viaje finalizado o ya cancelado.',
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
            new OA\Response(response: 409, description: 'El viaje ya finalizó o ya estaba cancelado, o el cliente intentó cancelar un viaje en curso.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function cancelar(Request $request, Viaje $viaje): JsonResponse
    {
        $this->authorize('ver', $viaje);

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

        // La transición se hace con la fila bloqueada para que una
        // aceptación, un inicio o una finalización concurrentes no se
        // pisen con la cancelación: se re-chequea el estado ya bloqueado.
        try {
            $viaje = DB::transaction(function () use ($viaje, $cuenta): Viaje {
                $actual = Viaje::query()->lockForUpdate()->findOrFail($viaje->id);

                if ($actual->estado->esFinal()) {
                    throw new \RuntimeException('No se puede cancelar un viaje que ya finalizó o fue cancelado.');
                }

                // Integridad financiera: un viaje en curso ya está prestando
                // el servicio; si el cliente pudiera cancelarlo a mitad de
                // camino, el viaje quedaría sin cobro. Solo un administrador
                // puede hacerlo.
                if ($actual->estado === EstadoViaje::EnCurso && $cuenta->rol !== RolPersona::Administrador) {
                    throw new \RuntimeException('Un viaje en curso solo puede cancelarlo un administrador.');
                }

                $actual->update(['estado' => EstadoViaje::Cancelado]);

                return $actual;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'message' => 'Viaje cancelado.',
            'data' => ViajeResource::arreglo($viaje, $request),
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

        // Corrección de auditoría (HALL-018): antes el promedio se
        // recalculaba con avg() fuera de cualquier transacción o lock. Dos
        // viajes del mismo conductor calificados casi al mismo tiempo podían
        // leer el mismo promedio "viejo" antes de que cualquiera de los dos
        // updates se aplicara, y el UPDATE que terminara ejecutándose último
        // pisaba el resultado del otro con un promedio que no reflejaba
        // ambas calificaciones.
        //
        // Ahora el recálculo ocurre dentro de una transacción que bloquea
        // (`lockForUpdate`) la fila del conductor: la segunda calificación
        // espera a que la primera termine de escribir su promedio antes de
        // volver a leer y recalcular, por lo que ambas quedan reflejadas.
        if ($viaje->conductor_id) {
            DB::transaction(function () use ($viaje): void {
                Conductor::query()
                    ->where('persona_id', $viaje->conductor_id)
                    ->lockForUpdate()
                    ->first();

                $promedio = Viaje::query()
                    ->where('conductor_id', $viaje->conductor_id)
                    ->whereNotNull('calificacion')
                    ->avg('calificacion');

                Conductor::query()
                    ->where('persona_id', $viaje->conductor_id)
                    ->update(['calificacion' => round((float) $promedio, 2)]);
            });
        }

        return response()->json([
            'message' => 'Viaje calificado.',
            'data' => ViajeResource::arreglo($viaje, $request),
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
            ->listosParaDespacho()
            ->orderBy('fecha_viaje')
            ->paginate(15);

        return response()->json(ViajeResource::paginado($viajes, $request));
    }
}
