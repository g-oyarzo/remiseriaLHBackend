<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActualizarTarifaRequest;
use App\Models\Tarifa;
use App\OpenApi\Schemas\CrearTarifaRequest as CrearTarifaRequestSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\TarifaSchema;
use App\OpenApi\Schemas\ValidationErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class TarifaController extends Controller
{
    /**
     * GET /api/v1/admin/tarifas
     *
     * Valores vigentes de tarifa base y precio por kilómetro (CU 24, paso 2).
     */
    #[OA\Get(
        path: '/admin/tarifas',
        summary: 'Consultar la tarifa vigente (administración)',
        description: 'Devuelve el último registro activo de la tabla tarifas. El historial completo de versiones está en GET /admin/tarifas/historial.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: TarifaSchema::class)])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'No hay tarifa vigente configurada.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(): JsonResponse
    {
        return $this->vigente();
    }

    /**
     * GET /api/v1/admin/tarifas/historial
     */
    #[OA\Get(
        path: '/admin/tarifas/historial',
        summary: 'Historial de versiones de la tarifa',
        description: 'Todas las tarifas creadas, vigentes y no vigentes, ordenadas de la más reciente a la más antigua.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Entre 1 y 100.', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK.',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: PaginationMeta::class),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: TarifaSchema::class)),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function historial(Request $request): JsonResponse
    {
        $tarifas = Tarifa::query()
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->paginate($this->porPagina($request));

        return response()->json($tarifas);
    }

    /**
     * GET /api/v1/tarifas/vigente
     */
    #[OA\Get(
        path: '/tarifas/vigente',
        summary: 'Consultar la tarifa vigente',
        description: 'Accesible a cualquier rol autenticado; se usa por ejemplo para mostrar una estimación de costo antes de solicitar un viaje.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: TarifaSchema::class)])),
            new OA\Response(response: 404, description: 'No hay tarifa vigente configurada.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function vigente(): JsonResponse
    {
        $tarifa = Tarifa::vigente();

        if (! $tarifa) {
            return response()->json([
                'message' => 'No hay tarifa vigente.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $tarifa]);
    }

    /**
     * PUT /api/v1/admin/tarifas
     *
     * Actualiza la tarifa del sistema (CU 24). No se sobrescribe la fila
     * vigente: se cierra (vigente_hasta) y se crea una versión nueva, de modo
     * que los viajes ya cotizados conservan la tarifa con la que se cotizaron.
     */
    #[OA\Put(
        path: '/admin/tarifas',
        summary: 'Actualizar la tarifa vigente',
        description: 'Crea una nueva versión de la tarifa en una transacción y cierra la anterior. Afecta solo a cotizaciones futuras: cada viaje guarda los valores con los que fue cotizado.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CrearTarifaRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Tarifa actualizada.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Tarifa actualizada exitosamente.'),
                new OA\Property(property: 'data', ref: TarifaSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Montos ausentes, no numéricos, menores o iguales a cero o con más de dos decimales.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function actualizar(ActualizarTarifaRequest $request): JsonResponse
    {
        $tarifa = $this->crearNuevaVersion($request);

        return response()->json([
            'message' => 'Tarifa actualizada exitosamente.',
            'data' => $tarifa,
        ]);
    }

    /**
     * POST /api/v1/admin/tarifas
     *
     * Equivalente a PUT /admin/tarifas; se conserva por compatibilidad con
     * los clientes que ya lo consumían.
     */
    #[OA\Post(
        path: '/admin/tarifas',
        summary: 'Configurar una nueva tarifa (compatibilidad)',
        description: 'Mismo comportamiento que PUT /admin/tarifas, pero responde 201. Se mantiene por compatibilidad.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CrearTarifaRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Tarifa creada.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Nueva tarifa configurada exitosamente.'),
                new OA\Property(property: 'data', ref: TarifaSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(ActualizarTarifaRequest $request): JsonResponse
    {
        $tarifa = $this->crearNuevaVersion($request);

        return response()->json([
            'message' => 'Nueva tarifa configurada exitosamente.',
            'data' => $tarifa,
        ], Response::HTTP_CREATED);
    }

    /**
     * Cierra la versión activa y crea la nueva en una única transacción.
     *
     * Corrección de auditoría (HALL-009): bloquear (lockForUpdate) las filas
     * activas hace que dos administradores guardando a la vez se apliquen en
     * serie, de modo que nunca quedan dos tarifas activas.
     */
    private function crearNuevaVersion(ActualizarTarifaRequest $request): Tarifa
    {
        $validated = $request->validated();
        $administradorId = $request->user()->persona_id;

        $tarifa = DB::transaction(function () use ($validated, $administradorId): Tarifa {
            $ahora = now();

            Tarifa::query()
                ->where('activa', true)
                ->lockForUpdate()
                ->update([
                    'activa' => false,
                    'vigente_hasta' => $ahora,
                ]);

            return Tarifa::query()->create([
                'precio_base' => $validated['precio_base'],
                'precio_por_km' => $validated['precio_por_km'],
                'zona' => $validated['zona'] ?? null,
                'activa' => true,
                'vigente_desde' => $ahora,
                'creada_por_persona_id' => $administradorId,
            ]);
        });

        // Tarifa::vigente() se cachea; se invalida al cambiar la versión activa.
        Tarifa::olvidarVigenteEnCache();

        return $tarifa->refresh();
    }
}
