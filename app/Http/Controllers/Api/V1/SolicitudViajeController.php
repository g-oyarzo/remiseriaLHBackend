<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarViajeTelefonicoRequest;
use App\Http\Requests\ReservarViajeRequest;
use App\Http\Requests\SolicitarViajeRequest;
use App\Http\Resources\ViajeResource;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\RegistrarViajeTelefonicoRequestSchema;
use App\OpenApi\Schemas\SolicitarViajeRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\OpenApi\Schemas\ViajeSchema;
use App\Services\Cotizacion\CotizacionNoDisponible;
use App\Services\Cotizacion\CotizacionNoUtilizable;
use App\Services\Viajes\ClienteTelefonicoService;
use App\Services\Viajes\CotizacionDesactualizada;
use App\Services\Viajes\DatosSolicitud;
use App\Services\Viajes\RegistroViajeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alta de viajes: solicitud inmediata (CU 01), reserva (CU 09) y registro
 * telefónico por un administrador (CU 10).
 */
class SolicitudViajeController extends Controller
{
    public function __construct(private readonly RegistroViajeService $registro) {}

    #[OA\Post(
        path: '/cliente/viajes/solicitar',
        summary: 'Solicitar un viaje inmediato',
        description: 'Cotiza en el servidor, guarda el viaje en estado "solicitado" con el snapshot de la cotización y dispara la asignación automática de conductor después del commit.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: SolicitarViajeRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Viaje solicitado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje solicitado exitosamente.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "cliente".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'No hay tarifa vigente, o monto_confirmado no coincide con la cotización vigente (se devuelve en "cotizacion").', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function solicitar(SolicitarViajeRequest $request): JsonResponse
    {
        return $this->registrar(
            $request,
            $request->datosSolicitud($request->user()->persona_id),
            'Viaje solicitado exitosamente.',
        );
    }

    #[OA\Post(
        path: '/cliente/viajes/reservar',
        summary: 'Reservar un viaje a futuro',
        description: 'Guarda el viaje en estado "reservado". No se ofrece a conductores hasta que entra en la ventana de despacho (VENTANA_DESPACHO_PROGRAMADOS_MINUTOS antes de fecha_viaje); en ese momento un job programado lo pasa a "solicitado" y dispara la asignación.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(allOf: [
            new OA\Schema(ref: SolicitarViajeRequestSchema::class),
            new OA\Schema(required: ['fecha_viaje'], properties: [
                new OA\Property(property: 'fecha_viaje', type: 'string', format: 'date-time', example: '2026-11-02T08:30:00-03:00'),
            ]),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Reserva registrada.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Reserva registrada exitosamente.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "cliente".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'No hay tarifa vigente o la cotización cambió.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación (incluye fecha fuera del rango permitido).', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function reservar(ReservarViajeRequest $request): JsonResponse
    {
        return $this->registrar(
            $request,
            $request->datosSolicitud($request->user()->persona_id, $request->fechaReserva()),
            'Reserva registrada exitosamente.',
        );
    }

    #[OA\Post(
        path: '/admin/viajes/registrar',
        summary: 'Registrar un viaje recibido por teléfono',
        description: 'El cliente se identifica con cliente_id o con sus datos (dni, nombre, apellido, telefono); si el DNI no existe se crea el cliente sin cuenta de acceso. Sin fecha_viaje se despacha de inmediato; con fecha_viaje se registra como reserva.',
        tags: ['Viajes'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: RegistrarViajeTelefonicoRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Viaje registrado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Viaje registrado exitosamente.'),
                new OA\Property(property: 'data', ref: ViajeSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'No hay tarifa vigente o la cotización cambió.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación, o el DNI pertenece a una persona con otro nombre.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function registrarTelefonico(RegistrarViajeTelefonicoRequest $request, ClienteTelefonicoService $clientes): JsonResponse
    {
        $clienteId = $request->validated('cliente_id')
            ?? $clientes->resolver($request->validated('cliente'))->persona_id;

        return $this->registrar(
            $request,
            $request->datosSolicitud($clienteId, $request->fechaReserva(), 'telefono', $request->user()->persona_id),
            'Viaje registrado exitosamente.',
        );
    }

    private function registrar(Request $request, DatosSolicitud $datos, string $mensaje): JsonResponse
    {
        try {
            $viaje = $this->registro->registrar($datos);
        } catch (CotizacionNoDisponible $e) {
            return CotizacionController::respuestaCotizacionNoDisponible($e);
        } catch (CotizacionNoUtilizable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['cotizacion_id' => [$e->getMessage()]],
            ], $e->status);
        } catch (CotizacionDesactualizada $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'cotizacion' => $e->cotizacionVigente->toArray(),
            ], Response::HTTP_CONFLICT);
        }

        $viaje->load(['cliente.persona', 'tarifa']);

        return response()->json([
            'message' => $mensaje,
            'data' => ViajeResource::arreglo($viaje, $request),
        ], Response::HTTP_CREATED);
    }
}
