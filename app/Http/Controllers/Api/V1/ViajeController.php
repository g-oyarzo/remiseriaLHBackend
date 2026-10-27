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
use App\OpenApi\Schemas\SimpleMessageResponse;
use App\OpenApi\Schemas\UpdateUbicacionRequest as UpdateUbicacionRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\OpenApi\Schemas\ViajeSchema;
use App\Services\Asignacion\AsignadorConductores;
use App\Services\Asignacion\RespuestaAsignacionInvalida;
use App\Services\Geolocalizacion\RegistroDeUbicaciones;
use App\Services\Geolocalizacion\UbicacionInconsistente;
use App\Services\Viajes\FinalizacionDeViajes;
use App\Services\Viajes\FinalizacionNoPermitida;
use App\ValueObjects\Coordinate;
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
     * POST|PATCH /api/v1/conductor/viajes/{viaje}/aceptar (CU 05)
     *
     * Solo el conductor al que el algoritmo de asignación le ofreció el
     * viaje, mientras la oferta esté vigente.
     */
    #[OA\Post(
        path: '/conductor/viajes/{viaje}/aceptar',
        summary: 'Aceptar la oferta de un viaje',
        description: 'Solo el conductor destinatario de la oferta pendiente (ver GET /conductor/viajes/pendientes), antes de que venza, estando activo, en servicio, con vehículo y sin otro viaje activo. Bloquea viaje, oferta y conductor (SELECT ... FOR UPDATE) para resolver carreras con el rechazo, el vencimiento y la cancelación. También se acepta PATCH por compatibilidad.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Viaje aceptado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje aceptado.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta no es conductor, o el viaje no le fue ofrecido.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'La oferta venció o ya no está vigente, o el conductor no está disponible.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function aceptar(Request $request, Viaje $viaje, AsignadorConductores $asignador): JsonResponse
    {
        if ($request->user()->rol !== RolPersona::Conductor) {
            return response()->json([
                'message' => 'Solo los conductores pueden aceptar viajes.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $viaje = $asignador->aceptar($viaje->id, $request->user()->persona_id);
        } catch (RespuestaAsignacionInvalida $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $viaje->load(['cliente.persona', 'conductor.persona', 'vehiculo']);

        return response()->json([
            'message' => 'Viaje aceptado.',
            'data' => ViajeResource::arreglo($viaje, $request),
        ]);
    }

    /**
     * POST /api/v1/conductor/viajes/{viaje}/rechazar (CU 05, excepción 2.a)
     */
    #[OA\Post(
        path: '/conductor/viajes/{viaje}/rechazar',
        summary: 'Rechazar la oferta de un viaje',
        description: 'Libera al conductor de la oferta y encola la asignación al siguiente candidato elegible. Al conductor no se le vuelve a ofrecer ese viaje.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Oferta rechazada.', content: new OA\JsonContent(ref: SimpleMessageResponse::class)),
            new OA\Response(response: 403, description: 'La cuenta no es conductor, o el viaje no le fue ofrecido.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'La oferta ya no está vigente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function rechazar(Request $request, Viaje $viaje, AsignadorConductores $asignador): JsonResponse
    {
        if ($request->user()->rol !== RolPersona::Conductor) {
            return response()->json([
                'message' => 'Solo los conductores pueden rechazar viajes.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $asignador->rechazar($viaje->id, $request->user()->persona_id);
        } catch (RespuestaAsignacionInvalida $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => 'Oferta rechazada. El viaje se ofrecerá a otro conductor.']);
    }

    /**
     * POST /api/v1/admin/viajes/{viaje}/reasignar
     */
    #[OA\Post(
        path: '/admin/viajes/{viaje}/reasignar',
        summary: 'Reiniciar la búsqueda de conductor',
        description: 'Para viajes "solicitado" cuya búsqueda se agotó (sin candidatos o sin aceptación). Vuelve a encolar la asignación automática; a los conductores que ya rechazaron o dejaron vencer la oferta no se les vuelve a ofrecer.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 202, description: 'Búsqueda reiniciada.', content: new OA\JsonContent(ref: SimpleMessageResponse::class)),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está buscando conductor.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function reasignar(Viaje $viaje, AsignadorConductores $asignador): JsonResponse
    {
        if (! $asignador->reiniciarBusqueda($viaje)) {
            return response()->json([
                'message' => 'Solo se puede reiniciar la búsqueda de un viaje en estado "solicitado".',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['message' => 'Búsqueda de conductor reiniciada.'], Response::HTTP_ACCEPTED);
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

        if ($viaje->conductor_id !== $request->user()->persona_id) {
            return response()->json([
                'message' => 'Solo el conductor asignado puede iniciar el viaje.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Con la fila bloqueada y el estado re-chequeado: sin esto, una
        // cancelación confirmada entre la lectura y el update quedaba pisada
        // y el viaje cancelado pasaba a "en curso".
        $iniciado = DB::transaction(function () use ($viaje): ?Viaje {
            $actual = Viaje::query()->lockForUpdate()->findOrFail($viaje->id);

            if ($actual->estado !== EstadoViaje::Aceptado) {
                return null;
            }

            $actual->update([
                'estado' => EstadoViaje::EnCurso,
                'iniciado_en' => now(),
                'distancia_recorrida_metros' => 0,
                'puntos_seguimiento' => 0,
            ]);

            return $actual;
        });

        if ($iniciado === null) {
            return response()->json([
                'message' => 'El viaje debe estar en estado "aceptado" para iniciarlo.',
            ], Response::HTTP_CONFLICT);
        }

        $viaje = $iniciado;

        return response()->json([
            'message' => 'Viaje iniciado.',
            'data' => ViajeResource::arreglo($viaje, $request),
        ]);
    }

    /**
     * POST|PATCH /api/v1/conductor/viajes/{viaje}/finalizar (CU 06)
     */
    #[OA\Post(
        path: '/conductor/viajes/{viaje}/finalizar',
        summary: 'Finalizar un viaje en curso',
        description: 'Solo el conductor asignado. Valida con ST_Distance_Sphere que la última posición registrada del conductor esté a no más de FINALIZACION_RADIO_TOLERANCIA_METROS (100 m) del destino; si se envían lat/lng se registran antes como un ping GPS. Calcula distancia y duración finales y el costo_final con la tarifa guardada en el viaje (no la vigente). También se acepta PATCH por compatibilidad.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: UpdateUbicacionRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Viaje finalizado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje finalizado.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta no es el conductor asignado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está "en curso", o no hay una ubicación reciente del conductor.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'El conductor está fuera del radio del destino (incluye distancia_al_destino_metros) o la ubicación enviada es inválida.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function finalizar(Request $request, Viaje $viaje, FinalizacionDeViajes $finalizacion, RegistroDeUbicaciones $ubicaciones): JsonResponse
    {
        $cuenta = $request->user();

        // El grupo de rutas ya exige rol conductor; se re-verifica (defensa
        // en profundidad) y solo el conductor asignado puede finalizar.
        if ($cuenta->rol !== RolPersona::Conductor || $viaje->conductor_id !== $cuenta->persona_id) {
            return response()->json([
                'message' => 'Solo el conductor asignado puede finalizar el viaje.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'precision_metros' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ]);

        $conductor = Conductor::query()->findOrFail($cuenta->persona_id);

        try {
            if (isset($validated['lat'], $validated['lng'])) {
                $ubicaciones->registrar(
                    $conductor,
                    new Coordinate(lat: (float) $validated['lat'], lng: (float) $validated['lng']),
                    isset($validated['precision_metros']) ? (float) $validated['precision_metros'] : null,
                );
                $conductor->refresh();
            }

            $viaje = $finalizacion->finalizar($viaje, $conductor);
        } catch (UbicacionInconsistente $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['lat' => [$e->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (FinalizacionNoPermitida $e) {
            return response()->json(['message' => $e->getMessage()] + $e->datos, $e->status);
        }

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
    public function cancelar(Request $request, Viaje $viaje, AsignadorConductores $asignador): JsonResponse
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
            $viaje = DB::transaction(function () use ($viaje, $cuenta, $asignador): Viaje {
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

                $asignador->retirarOfertas($actual);
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
     * GET /api/v1/conductor/viajes/pendientes
     *
     * Ofertas vigentes del conductor autenticado (CU 05, paso 1).
     */
    #[OA\Get(
        path: '/conductor/viajes/pendientes',
        summary: 'Ofertas de viaje pendientes del conductor',
        description: 'Viajes que el algoritmo de asignación le ofreció al conductor autenticado y que todavía puede aceptar o rechazar. Cada elemento incluye "asignacion" con el id y el vencimiento de la oferta. Antes devolvía todos los viajes solicitados (modelo "el primero que acepta"); con la asignación automática cada viaje se ofrece a un conductor por vez.',
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
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(allOf: [
                                new OA\Schema(ref: ViajeSchema::class),
                                new OA\Schema(properties: [
                                    new OA\Property(property: 'asignacion', type: 'object', properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 12),
                                        new OA\Property(property: 'expira_en', type: 'string', format: 'date-time'),
                                        new OA\Property(property: 'distancia_metros', type: 'integer', nullable: true, example: 850),
                                    ]),
                                ]),
                            ])),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "conductor".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function pendientes(Request $request): JsonResponse
    {
        $conductorId = $request->user()->persona_id;

        $viajes = Viaje::query()
            ->with([
                'cliente.persona',
                'tarifa',
                'asignaciones' => fn ($q) => $q->pendientes()->where('conductor_id', $conductorId),
            ])
            ->where('estado', EstadoViaje::Solicitado)
            ->whereHas('asignaciones', fn ($q) => $q->pendientes()
                ->where('conductor_id', $conductorId)
                ->where('expira_en', '>', now()))
            ->orderBy('fecha_viaje')
            ->paginate($this->porPagina($request));

        return response()->json($viajes->through(function (Viaje $viaje) use ($request): array {
            $asignacion = $viaje->asignaciones->first();

            return ViajeResource::arreglo($viaje, $request) + [
                'asignacion' => [
                    'id' => $asignacion->id,
                    'expira_en' => $asignacion->expira_en->toIso8601String(),
                    'distancia_metros' => $asignacion->distancia_metros,
                ],
            ];
        }));
    }
}
