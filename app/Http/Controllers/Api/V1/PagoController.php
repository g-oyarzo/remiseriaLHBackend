<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoPago;
use App\Enums\RolPersona;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegistrarPagoRequest;
use App\Models\Pago;
use App\Models\Viaje;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\PagoSchema;
use App\OpenApi\Schemas\RegistrarPagoRequest as RegistrarPagoRequestSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
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
        description: 'Conductor asignado o administrador. El monto debe coincidir exactamente con el importe calculado por el backend al finalizar (costo_final). Efectivo queda "confirmado" (registro interno del cobro). QR y tarjetas quedan "pendiente" con su referencia_externa hasta que un administrador los concilia: el backend no tiene integración con un proveedor de pagos y no los da por cobrados. Reenviar el mismo pago devuelve 200 con el pago existente (idempotente).',
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
            'data' => self::conComprobante($pago),
        ], $creado ? Response::HTTP_CREATED : Response::HTTP_OK);
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
        description: 'El administrador verificó la operación con el proveedor (billetera, terminal o banco) y la confirma o la rechaza (p. ej. fondos insuficientes, CU 07 excepción 3.a). Un pago rechazado puede volver a registrarse.',
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
            new OA\Response(response: 409, description: 'El pago no está pendiente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
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

        return response()->json(['message' => 'Pago conciliado.', 'data' => self::conComprobante($pago)]);
    }

    /**
     * Agrega la URL de descarga del comprobante cuando el pago está
     * confirmado. La descarga requiere el mismo token Bearer.
     *
     * @return array<string, mixed>
     */
    private static function conComprobante(Pago $pago): array
    {
        return $pago->toArray() + [
            'comprobante_url' => $pago->estado === EstadoPago::Confirmado
                ? url("/api/v1/viajes/{$pago->viaje_id}/comprobante")
                : null,
        ];
    }
}
