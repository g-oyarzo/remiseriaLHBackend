<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoConductor;
use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Http\Controllers\Controller;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Persona;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\OpenApi\Schemas\CambiarEstadoConductorRequest as CambiarEstadoConductorRequestSchema;
use App\OpenApi\Schemas\ConductorSchema;
use App\OpenApi\Schemas\CrearConductorRequest as CrearConductorRequestSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\ToggleServicioRequest as ToggleServicioRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\ValueObjects\Coordinate;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
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
     * POST /api/v1/admin/conductores
     *
     * Corrección de auditoría (HALL-011): antes no existía ningún endpoint
     * para que un administrador diera de alta a un conductor; había que
     * hacerlo a mano contra la base de datos. Crea Persona + Conductor +
     * Cuenta (rol conductor) en una única transacción.
     */
    #[OA\Post(
        path: '/admin/conductores',
        summary: 'Dar de alta a un conductor',
        description: 'Crea Persona + Conductor + Cuenta (rol "conductor") en una transacción atómica. El vehículo se puede crear en el mismo request (objeto `vehiculo`) o asignar uno existente (`vehiculo_id`); ambos son excluyentes.',
        tags: ['Admin - Conductores'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CrearConductorRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Conductor creado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Conductor creado exitosamente.'),
                new OA\Property(property: 'data', ref: ConductorSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación (dni/email/cuil duplicados, vehiculo_id inexistente o ya asignado, etc.).', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->reglasConductor(null, null, true));

        // El unique() de conductores.vehiculo_id en BD ya lo garantiza, pero
        // se valida antes también para devolver un 422 claro en lugar de un
        // 500 si dos altas casi simultáneas apuntan al mismo vehículo.
        if (! empty($validated['vehiculo_id']) &&
            Conductor::query()->where('vehiculo_id', $validated['vehiculo_id'])->exists()) {
            throw ValidationException::withMessages([
                'vehiculo_id' => ['Este vehículo ya está asignado a otro conductor.'],
            ]);
        }

        try {
            $conductor = DB::transaction(function () use ($validated): Conductor {
                $persona = Persona::query()->create([
                    'dni' => $validated['dni'],
                    'nombre' => $validated['nombre'],
                    'apellido' => $validated['apellido'],
                    'telefono' => $validated['telefono'] ?? null,
                ]);

                $vehiculoId = $validated['vehiculo_id'] ?? null;
                if (! empty($validated['vehiculo'])) {
                    $vehiculoId = Vehiculo::query()->create($this->atributosVehiculo($validated['vehiculo']))->id;
                }

                $conductor = Conductor::query()->create([
                    'persona_id' => $persona->id,
                    'cuil' => $validated['cuil'],
                    'fecha_nacimiento' => $validated['fecha_nacimiento'],
                    'domicilio_localidad' => $validated['domicilio_localidad'],
                    'domicilio_calle' => $validated['domicilio_calle'],
                    'domicilio_numero' => $validated['domicilio_numero'],
                    'vehiculo_id' => $vehiculoId,
                ]);

                Cuenta::query()->create([
                    'persona_id' => $persona->id,
                    'email' => $validated['email'],
                    'password' => $validated['password'], // cast 'hashed' en el modelo
                    'rol' => RolPersona::Conductor,
                ]);

                return $conductor;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'dni' => ['No fue posible crear el conductor: verifique que el dni, email, cuil y vehículo no estén ya en uso.'],
                ]);
            }

            throw $e;
        }

        $conductor->load(['persona.cuenta', 'vehiculo.marca']);

        return response()->json([
            'message' => 'Conductor creado exitosamente.',
            'data' => $conductor,
        ], Response::HTTP_CREATED);
    }

    /**
     * PUT /api/v1/admin/conductores/{conductor}
     *
     * Reemplazo completo de los datos personales, la cuenta y el vehículo del
     * conductor. Los campos ausentes no se conservan: vehiculo_id = null o
     * ausencia de "vehiculo" desasigna el vehículo. La contraseña es opcional
     * (si no se envía, se mantiene la actual). No cambia el estado: para dar
     * de baja se usa DELETE.
     */
    #[OA\Put(
        path: '/admin/conductores/{conductor}',
        summary: 'Actualizar datos de un conductor (reemplazo completo)',
        description: 'Actualiza Persona, Conductor, Cuenta y Vehículo. Si el vehículo asignado existe y se envía el objeto "vehiculo", se actualiza en lugar de crear uno nuevo. Un conductor eliminado no se puede modificar.',
        tags: ['Admin - Conductores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conductor', in: 'path', required: true, description: 'persona_id del conductor.', schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CrearConductorRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Conductor actualizado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Conductor actualizado exitosamente.'),
                new OA\Property(property: 'data', ref: ConductorSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Conductor inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación, duplicados (dni, email, cuil, patente, vehiculo_id) o conductor eliminado.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function update(Request $request, Conductor $conductor): JsonResponse
    {
        if ($conductor->estado === EstadoConductor::Eliminado) {
            throw ValidationException::withMessages([
                'estado' => ['No se puede modificar un conductor dado de baja.'],
            ]);
        }

        $validated = $request->validate(
            $this->reglasConductor($conductor->persona_id, $conductor->vehiculo_id, false),
        );

        if (! empty($validated['vehiculo_id']) &&
            Conductor::query()
                ->where('vehiculo_id', $validated['vehiculo_id'])
                ->where('persona_id', '!=', $conductor->persona_id)
                ->exists()) {
            throw ValidationException::withMessages([
                'vehiculo_id' => ['Este vehículo ya está asignado a otro conductor.'],
            ]);
        }

        $cuenta = Cuenta::query()->where('persona_id', $conductor->persona_id)->first();
        if (! $cuenta && empty($validated['password'])) {
            throw ValidationException::withMessages([
                'password' => ['Se requiere una contraseña: el conductor no tiene cuenta asociada.'],
            ]);
        }

        try {
            DB::transaction(function () use ($conductor, $validated, $cuenta): void {
                $conductor->persona()->update([
                    'dni' => $validated['dni'],
                    'nombre' => $validated['nombre'],
                    'apellido' => $validated['apellido'],
                    'telefono' => $validated['telefono'] ?? null,
                ]);

                $vehiculoId = $validated['vehiculo_id'] ?? null;
                if (! empty($validated['vehiculo'])) {
                    $atributos = $this->atributosVehiculo($validated['vehiculo']);
                    $vehiculoActual = $conductor->vehiculo_id ? Vehiculo::query()->find($conductor->vehiculo_id) : null;

                    if ($vehiculoActual) {
                        $vehiculoActual->update($atributos);
                        $vehiculoId = $vehiculoActual->id;
                    } else {
                        $vehiculoId = Vehiculo::query()->create($atributos)->id;
                    }
                }

                $conductor->update([
                    'cuil' => $validated['cuil'],
                    'fecha_nacimiento' => $validated['fecha_nacimiento'],
                    'domicilio_localidad' => $validated['domicilio_localidad'],
                    'domicilio_calle' => $validated['domicilio_calle'],
                    'domicilio_numero' => $validated['domicilio_numero'],
                    'vehiculo_id' => $vehiculoId,
                ]);

                // Solo se toca email y contraseña. El rol NO se modifica: un
                // administrador que también sea conductor no debe degradarse.
                $datosCuenta = ['email' => $validated['email']];
                if (! empty($validated['password'])) {
                    $datosCuenta['password'] = $validated['password']; // cast 'hashed'
                }

                if ($cuenta) {
                    $cuenta->update($datosCuenta);
                } else {
                    Cuenta::query()->create($datosCuenta + [
                        'persona_id' => $conductor->persona_id,
                        'rol' => RolPersona::Conductor,
                    ]);
                }
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'dni' => ['No fue posible actualizar el conductor: verifique que el dni, email, cuil, patente o vehículo no estén ya en uso.'],
                ]);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Conductor actualizado exitosamente.',
            'data' => $conductor->fresh(['persona.cuenta', 'vehiculo.marca']),
        ]);
    }

    /**
     * Reglas compartidas por alta (POST) y reemplazo (PUT). Cuando se pasa
     * $personaId, las unicidades ignoran el registro propio: reenviar los
     * mismos datos de un conductor no devuelve 422.
     *
     * vehiculo_id y vehiculo son excluyentes: se asigna un vehículo existente
     * o se crea uno nuevo, nunca ambas cosas.
     *
     * @return array<string, mixed>
     */
    private function reglasConductor(?int $personaId, ?int $vehiculoActualId, bool $esAlta): array
    {
        return [
            'dni' => ['required', 'string', 'max:15', Rule::unique('personas', 'dni')->ignore($personaId)],
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('cuentas', 'email')->ignore($personaId, 'persona_id')],
            'password' => $esAlta ? ['required', 'string', 'min:8'] : ['nullable', 'string', 'min:8'],
            'cuil' => ['required', 'string', 'max:15', Rule::unique('conductores', 'cuil')->ignore($personaId, 'persona_id')],
            'fecha_nacimiento' => ['required', 'date', 'before:-18 years'],
            'domicilio_localidad' => ['required', 'string', 'max:100'],
            'domicilio_calle' => ['required', 'string', 'max:100'],
            'domicilio_numero' => ['required', 'string', 'max:15'],
            'vehiculo_id' => ['nullable', 'integer', 'exists:vehiculos,id', 'prohibits:vehiculo'],
            'vehiculo' => ['nullable', 'array'],
            'vehiculo.marca_id' => ['required_with:vehiculo', 'integer', 'exists:marcas,id'],
            'vehiculo.modelo' => ['required_with:vehiculo', 'string', 'max:60'],
            'vehiculo.patente' => ['required_with:vehiculo', 'string', 'max:10', Rule::unique('vehiculos', 'patente')->ignore($vehiculoActualId)],
            'vehiculo.color' => ['required_with:vehiculo', 'string', 'max:30'],
            'vehiculo.anio' => ['required_with:vehiculo', 'integer', 'between:1900,'.(now()->year + 1)],
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function atributosVehiculo(array $datos): array
    {
        return [
            'marca_id' => $datos['marca_id'],
            'modelo' => $datos['modelo'],
            'patente' => $datos['patente'],
            'color' => $datos['color'],
            'anio' => $datos['anio'],
        ];
    }

    /**
     * PATCH /api/v1/admin/conductores/{conductor}/estado
     *
     * Corrección de auditoría (sección 9, "Funcionalidades faltantes"): baja
     * lógica de conductor por admin. El enum EstadoConductor ya contemplaba
     * el valor "eliminado" (ver scopeDisponibles()), pero no existía ningún
     * endpoint para que un administrador lo estableciera. También sirve
     * para reactivar un conductor dado de baja (estado -> "activo").
     */
    #[OA\Patch(
        path: '/admin/conductores/{conductor}/estado',
        summary: 'Dar de baja (o reactivar) a un conductor',
        description: 'Baja lógica: no borra el legajo, lo marca "eliminado" y lo saca de servicio automáticamente. También permite reactivarlo a "activo".',
        tags: ['Admin - Conductores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conductor', in: 'path', required: true, description: 'persona_id del conductor.', schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CambiarEstadoConductorRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Estado actualizado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Estado del conductor actualizado.'),
                new OA\Property(property: 'data', ref: ConductorSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Conductor inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function actualizarEstado(Request $request, Conductor $conductor): JsonResponse
    {
        $validated = $request->validate([
            'estado' => ['required', new Enum(EstadoConductor::class)],
        ]);

        // La baja pasa por la misma validación que DELETE (sin viajes activos
        // ni reservas programadas). Repetirla sobre un conductor ya dado de
        // baja es una no-op.
        if ($validated['estado'] === EstadoConductor::Eliminado->value) {
            if ($conductor->estado !== EstadoConductor::Eliminado) {
                $this->darDeBaja($conductor);
            }
        } else {
            $conductor->update(['estado' => $validated['estado']]);
        }

        return response()->json([
            'message' => 'Estado del conductor actualizado.',
            'data' => $conductor->fresh(['persona.cuenta', 'vehiculo.marca']),
        ]);
    }

    /**
     * DELETE /api/v1/admin/conductores/{conductor}
     *
     * Baja lógica: el legajo no se borra. Se rechaza si el conductor tiene
     * viajes activos o reservas programadas vigentes.
     */
    #[OA\Delete(
        path: '/admin/conductores/{conductor}',
        summary: 'Dar de baja lógica a un conductor',
        description: 'Cambia el estado a "eliminado", lo saca de servicio y libera su vehículo. No borra el registro. Se rechaza (422) si tiene viajes aceptados o en curso, o reservas programadas vigentes (tipo programado en estado solicitado o aceptado con fecha futura).',
        tags: ['Admin - Conductores'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'conductor', in: 'path', required: true, description: 'persona_id del conductor.', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Conductor dado de baja.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Conductor dado de baja exitosamente.'),
                new OA\Property(property: 'data', ref: ConductorSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Conductor inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'El conductor ya está eliminado o tiene viajes activos / reservas programadas.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function destroy(Conductor $conductor): JsonResponse
    {
        $this->darDeBaja($conductor);

        return response()->json([
            'message' => 'Conductor dado de baja exitosamente.',
            'data' => $conductor->fresh(['persona.cuenta', 'vehiculo.marca']),
        ]);
    }

    /**
     * Aplica la baja lógica en una transacción con bloqueo de fila, para que
     * una asignación de viaje concurrente no se cuele entre la validación y
     * el cambio de estado.
     */
    private function darDeBaja(Conductor $conductor): void
    {
        DB::transaction(function () use ($conductor): void {
            $bloqueado = Conductor::query()->whereKey($conductor->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueado->estado === EstadoConductor::Eliminado) {
                throw ValidationException::withMessages([
                    'estado' => ['El conductor ya se encuentra dado de baja.'],
                ]);
            }

            if ($this->viajesBloqueantesDeBaja($bloqueado)->exists()) {
                throw ValidationException::withMessages([
                    'conductor' => ['No se puede dar de baja: el conductor tiene viajes activos o reservas programadas.'],
                ]);
            }

            // Sacarlo de servicio evita que siga apareciendo como disponible
            // para despacho; desasignar el vehículo lo libera para otro
            // conductor (el registro del vehículo no se borra).
            $bloqueado->update([
                'estado' => EstadoConductor::Eliminado,
                'en_servicio' => false,
                'vehiculo_id' => null,
            ]);

            $conductor->setRawAttributes($bloqueado->getAttributes(), true);
        });
    }

    /**
     * Viajes que impiden la baja: activos (aceptado o en curso) o reservas
     * programadas vigentes (tipo programado, pendientes, con fecha futura).
     *
     * @return \Illuminate\Database\Eloquent\Builder<Viaje>
     */
    private function viajesBloqueantesDeBaja(Conductor $conductor): \Illuminate\Database\Eloquent\Builder
    {
        return Viaje::query()
            ->where('conductor_id', $conductor->persona_id)
            ->where(function ($query): void {
                $query->whereIn('estado', [EstadoViaje::Aceptado, EstadoViaje::EnCurso])
                    ->orWhere(function ($query): void {
                        $query->where('tipo', TipoViaje::Programado)
                            ->whereIn('estado', [EstadoViaje::Solicitado, EstadoViaje::Aceptado])
                            ->where('fecha_viaje', '>=', now());
                    });
            });
    }

    /**
     * GET /api/v1/conductores/cercanos
     *
     * Busca conductores activos y en servicio cerca de una ubicación (para despachador).
     */
    #[OA\Get(
        path: '/conductores/cercanos',
        summary: 'Buscar conductores disponibles cerca de una ubicación',
        description: 'Corrección: este endpoint estaba completamente implementado pero nunca tuvo una ruta registrada (era inalcanzable). Solo administradores.',
        tags: ['Admin - Conductores'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'lat', in: 'query', required: true, schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'lng', in: 'query', required: true, schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'radio_metros', in: 'query', required: false, schema: new OA\Schema(type: 'number', default: 5000)),
            new OA\Parameter(name: 'limite', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: ConductorSchema::class)),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function cercanos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radio_metros' => ['nullable', 'numeric', 'min:100', 'max:50000'],
            // HALL-016: antes no había límite ni paginación; con muchos
            // conductores disponibles se serializaban y enviaban todos.
            'limite' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $origen = new Coordinate(
            lat: (float) $validated['lat'],
            lng: (float) $validated['lng']
        );

        $radio = isset($validated['radio_metros']) ? (float) $validated['radio_metros'] : 5000.0;
        $limite = isset($validated['limite']) ? (int) $validated['limite'] : 20;

        $conductores = Conductor::query()
            ->with(['persona', 'vehiculo.marca'])
            ->disponibles()
            ->cercanos($origen, $radio)
            ->limit($limite)
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