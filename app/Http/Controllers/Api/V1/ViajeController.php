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
use App\ValueObjects\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;
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
