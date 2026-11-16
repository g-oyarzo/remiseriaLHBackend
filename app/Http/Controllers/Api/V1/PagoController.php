<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoPago;
use App\Enums\RolPersona;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarPagoRequest;
use App\Models\MedioPagoGuardado;
use App\Models\Pago;
use App\Models\Viaje;
use App\OpenApi\Schemas\CobroMercadoPagoSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\PagoSchema;
use App\OpenApi\Schemas\RegistrarPagoRequest as RegistrarPagoRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use App\Services\Pagos\PagoRechazado;
use App\Services\Pagos\RegistroDePagos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class PagoController extends Controller
{
    public function __construct(private readonly RegistroDePagos $pagos) {}

    /**
     * POST /api/v1/conductor/viajes/{viaje}/pago (CU 07)
     */
    #[OA\Post(
        path: '/conductor/viajes/{viaje}/pago',
        summary: 'Registrar el pago de un viaje finalizado',
        description: 'Conductor asignado o administrador. El monto debe coincidir exactamente con el importe del viaje (costo_final = precio cerrado cotizado). Efectivo queda "confirmado" (registro interno del cobro). QR y tarjetas cobrados fuera de Mercado Pago quedan "pendiente" con su referencia_externa hasta que un administrador los concilia. Para cobrar con Mercado Pago usar POST /viajes/{viaje}/pago/mercadopago (se confirma solo). Un cobro de Mercado Pago pendiente puede reemplazarse con este endpoint (p. ej. el pasajero pagó en efectivo). Reenviar el mismo pago devuelve 200 con el pago existente (idempotente).',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: RegistrarPagoRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Pago registrado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Pago registrado exitosamente.'),
                new OA\Property(property: 'data', ref: PagoSchema::class),
            ])),
            new OA\Response(response: 200, description: 'El mismo pago ya estaba registrado (reintento idempotente).', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'El pago ya estaba registrado.'),
                new OA\Property(property: 'data', ref: PagoSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta no es el conductor asignado ni administrador.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está finalizado, ya tiene otro pago, o la referencia ya se usó en otro pago.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Datos inválidos, o el monto no coincide con el importe del viaje (se informa importe_esperado).', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(RegistrarPagoRequest $request, Viaje $viaje): JsonResponse
    {
        $cuenta = $request->user();

        // Admin o el conductor del viaje pueden registrar el pago.
        if ($cuenta->rol === RolPersona::Cliente ||
            ($cuenta->rol === RolPersona::Conductor && $viaje->conductor_id !== $cuenta->persona_id)) {
            return response()->json([
                'message' => 'No tiene permisos para registrar un pago para este viaje.',
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            [$pago, $creado] = $this->pagos->registrar(
                $viaje->id,
                $request->metodo(),
                $request->monto(),
                $request->validated('referencia_externa'),
                $cuenta->persona_id,
            );
        } catch (PagoRechazado $e) {
            return response()->json(['message' => $e->getMessage()] + $e->datos, $e->status);
        }

        return response()->json([
            'message' => $creado ? 'Pago registrado exitosamente.' : 'El pago ya estaba registrado.',
            'data' => self::conComprobante($pago, $cuenta->rol === RolPersona::Administrador),
        ], $creado ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * POST /api/v1/viajes/{viaje}/pago/mercadopago
     */
    #[OA\Post(
        path: '/viajes/{viaje}/pago/mercadopago',
        summary: 'Generar el cobro con Mercado Pago de un viaje finalizado',
        description: 'Cliente del viaje, conductor asignado o administrador. Crea un pago "pendiente" por el importe exacto del viaje y un enlace de Checkout Pro (mostrarlo al pasajero como link o QR). Mercado Pago confirma el cobro automáticamente por webhook (el backend verifica el pago con la API del proveedor); el cambio se difunde como "pago.actualizado" en el canal privado viaje.{id}. Si ya hay un enlace vigente se devuelve el mismo (200); si venció, se genera otro (201).',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'Enlace de pago generado.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: CobroMercadoPagoSchema::class)])),
            new OA\Response(response: 200, description: 'Se reutiliza el enlace vigente.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: CobroMercadoPagoSchema::class)])),
            new OA\Response(response: 403, description: 'No tiene acceso a este viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está finalizado, ya está cobrado o tiene otro cobro pendiente de conciliación.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 503, description: 'Mercado Pago no está habilitado o no respondió; reintentar.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function mercadoPago(Request $request, Viaje $viaje, CobrosMercadoPago $cobros): JsonResponse
    {
        $this->authorize('ver', $viaje);

        try {
            [$pago, $nuevo] = $cobros->iniciarCobro($viaje->id, $request->user()->persona_id);
        } catch (PagoRechazado $e) {
            return response()->json(['message' => $e->getMessage()] + $e->datos, $e->status);
        } catch (MercadoPagoNoDisponible) {
            return response()->json([
                'message' => 'No se pudo generar el cobro con Mercado Pago. Reintentá en unos minutos o cobrá por otro medio.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($pago->preferencia_id === null) {
            // El pago cambió (p. ej. se registró en efectivo) mientras se
            // creaba la preferencia: no se ofrece el enlace.
            return response()->json(['message' => 'El cobro del viaje cambió; consultá su estado.'], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'message' => $nuevo ? 'Enlace de pago generado.' : 'El enlace de pago sigue vigente.',
            'data' => [
                'pago_id' => $pago->id,
                'viaje_id' => $pago->viaje_id,
                'monto' => (string) $pago->monto,
                'moneda' => 'ARS',
                'estado' => $pago->estado->value,
                'enlace_pago' => $pago->enlace_pago,
                'vigente_hasta' => $pago->preferencia_expira_en?->toIso8601String(),
            ],
        ], $nuevo ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * POST /api/v1/cliente/viajes/{viaje}/pago/tarjeta
     */
    #[OA\Post(
        path: '/cliente/viajes/{viaje}/pago/tarjeta',
        summary: 'Pagar un viaje finalizado con una tarjeta guardada',
        description: 'Solo el pasajero del viaje. El frontend genera un token nuevo con MercadoPago.js (createCardToken con el card_id de la tarjeta guardada y el CVV que ingresa el pasajero; Mercado Pago no guarda el CVV) y lo envía con medio_pago_id. El backend crea el pago por el importe exacto del viaje, lo consulta en Mercado Pago y lo confirma solo si está aprobado. Si había un enlace de pago vigente, se vence. Un rechazo deja el pago pendiente para reintentar o pagar por otro medio.',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['medio_pago_id', 'token'], properties: [
            new OA\Property(property: 'medio_pago_id', type: 'integer'),
            new OA\Property(property: 'token', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Resultado del cobro (aprobado: estado "confirmado"; rechazado: "pendiente" con mp_status_detail).'),
            new OA\Response(response: 403, description: 'No es el pasajero del viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje o tarjeta inexistentes (o ajenos).', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no está finalizado, ya está cobrado, o hay otro cobro en curso.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Datos inválidos o Mercado Pago rechazó el token (pedir el CVV de nuevo).', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
            new OA\Response(response: 503, description: 'Mercado Pago no está habilitado o no respondió.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function tarjeta(Request $request, Viaje $viaje, CobrosMercadoPago $cobros): JsonResponse
    {
        $validated = $request->validate([
            'medio_pago_id' => ['required', 'integer'],
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],
        ]);

        if ($viaje->cliente_id !== $request->user()->persona_id) {
            return response()->json(['message' => 'Solo el pasajero del viaje puede pagarlo con sus tarjetas.'], Response::HTTP_FORBIDDEN);
        }

        $medio = MedioPagoGuardado::query()->activos()
            ->where('cliente_id', $request->user()->persona_id)
            ->findOrFail($validated['medio_pago_id']);

        try {
            $cobro = $cobros->cobrarConTarjeta($viaje->id, $request->user()->persona_id, $medio, $validated['token']);
        } catch (PagoRechazado $e) {
            return response()->json(['message' => $e->getMessage()] + $e->datos, $e->status);
        } catch (MercadoPagoNoDisponible) {
            return response()->json([
                'message' => 'No se pudo completar el cobro con Mercado Pago. Consultá el estado del pago antes de reintentar.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $pago = $cobro['pago'];

        return response()->json([
            'message' => match ($cobro['resultado']) {
                'confirmado' => 'Pago aprobado.',
                'requiere_revision' => 'El pago requiere revisión administrativa.',
                default => $cobro['mp_status'] === 'rejected' ? 'Mercado Pago rechazó el pago.' : 'El pago está en proceso.',
            },
            'data' => self::conComprobante($pago, false) + [
                'mp_status' => $cobro['mp_status'],
                'mp_status_detail' => $cobro['mp_status_detail'],
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/pagos/{pago}/reintegrar
     */
    #[OA\Post(
        path: '/admin/pagos/{pago}/reintegrar',
        summary: 'Reintegrar un cobro de Mercado Pago aprobado que no se aplicó',
        description: 'Para pagos dobles o tardíos (el pasajero pagó el enlace después de pagar en efectivo, o pagó dos veces). Solo acepta operaciones que el historial registra como "mercadopago_aprobado_sin_aplicar" o "mercadopago_importe_no_coincide"; nunca la operación que confirmó el pago. Idempotente. El resultado definitivo llega por webhook.',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'pago', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['mp_operacion_id'], properties: [
            new OA\Property(property: 'mp_operacion_id', type: 'string', example: '987654321'),
        ])),
        responses: [
            new OA\Response(response: 202, description: 'Reintegro solicitado (o ya se había solicitado).'),
            new OA\Response(response: 409, description: 'La operación no es un cobro no aplicado de este pago.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 503, description: 'Mercado Pago no está habilitado o no respondió.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function reintegrar(Request $request, Pago $pago, CobrosMercadoPago $cobros): JsonResponse
    {
        $validated = $request->validate([
            'mp_operacion_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
        ]);

        try {
            $resultado = $cobros->reintegrarOperacionNoAplicada($pago->id, $validated['mp_operacion_id'], $request->user()->persona_id);
        } catch (PagoRechazado $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (MercadoPagoNoDisponible) {
            return response()->json(['message' => 'Mercado Pago no respondió; reintentá en unos minutos.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'message' => $resultado === 'ya_solicitado' ? 'El reintegro ya se había solicitado.' : 'Reintegro solicitado a Mercado Pago.',
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * GET /api/v1/admin/pagos
     */
    #[OA\Get(
        path: '/admin/pagos',
        summary: 'Listar pagos',
        description: 'Para conciliar cobros digitales: filtrar con estado=pendiente.',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'estado', in: 'query', required: false, schema: new OA\Schema(ref: EstadoPago::class)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Entre 1 y 100.', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: PaginationMeta::class),
                new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: PagoSchema::class))]),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoPago::class)],
        ]);

        $pagos = Pago::query()
            ->when($validated['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->orderByDesc('id')
            ->paginate($this->porPagina($request));

        return response()->json($pagos);
    }

    /**
     * PATCH /api/v1/admin/pagos/{pago}/conciliar
     */
    #[OA\Patch(
        path: '/admin/pagos/{pago}/conciliar',
        summary: 'Conciliar un cobro digital pendiente',
        description: 'El administrador verificó la operación con el proveedor (billetera, terminal o banco) y la confirma o la rechaza (p. ej. fondos insuficientes, CU 07 excepción 3.a). Un pago rechazado puede volver a registrarse. Los cobros de Mercado Pago no se concilian a mano (409): se confirman al verificarlos con la API del proveedor.',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'pago', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['estado'], properties: [
            new OA\Property(property: 'estado', type: 'string', enum: ['confirmado', 'rechazado']),
            new OA\Property(property: 'observacion', type: 'string', maxLength: 255, nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Pago conciliado.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: PagoSchema::class)])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El pago no está pendiente, o es un cobro de Mercado Pago.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function conciliar(Request $request, Pago $pago): JsonResponse
    {
        $validated = $request->validate([
            'estado' => ['required', Rule::in([EstadoPago::Confirmado->value, EstadoPago::Rechazado->value])],
            'observacion' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $pago = $this->pagos->conciliar(
                $pago->id,
                EstadoPago::from($validated['estado']),
                $request->user()->persona_id,
                $validated['observacion'] ?? null,
            );
        } catch (PagoRechazado $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => 'Pago conciliado.', 'data' => self::conComprobante($pago, true)]);
    }

    /**
     * Agrega la URL de descarga del comprobante cuando el pago está
     * confirmado. La descarga requiere el mismo token Bearer.
     *
     * @return array<string, mixed>
     */
    private static function conComprobante(Pago $pago, bool $esAdmin): array
    {
        // SEC-03: el historial de auditoría y los datos internos del pago
        // son solo para administradores.
        return ($esAdmin ? $pago->toArray() : $pago->vistaPublica()) + [
            'comprobante_url' => $pago->estado === EstadoPago::Confirmado
                ? url("/api/v1/viajes/{$pago->viaje_id}/comprobante")
                : null,
        ];
    }
}
