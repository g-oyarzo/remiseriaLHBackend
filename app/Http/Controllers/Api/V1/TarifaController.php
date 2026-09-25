<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
     */
    #[OA\Get(
        path: '/admin/tarifas',
        summary: 'Listar tarifas (historial)',
        description: 'Todas las tarifas creadas, vigentes y no vigentes, ordenadas por fecha de creación descendente.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))],
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
    public function index(Request $request): JsonResponse
    {
        $tarifas = Tarifa::query()
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15));

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
     * POST /api/v1/admin/tarifas
     *
     * Crea una nueva tarifa y desactiva la anterior (CU 24).
     */
    #[OA\Post(
        path: '/admin/tarifas',
        summary: 'Configurar una nueva tarifa',
        description: 'Desactiva automáticamente la tarifa vigente actual (activa = false, vigente_hasta = now()) y crea la nueva como vigente.',
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
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'precio_base' => ['required', 'numeric', 'min:0'],
            'precio_por_km' => ['required', 'numeric', 'min:0'],
            'zona' => ['nullable', 'string', 'max:60'],
        ]);

        // Corrección de auditoría (HALL-009): antes se leía la tarifa
        // vigente y se desactivaba en un paso, y se creaba la nueva en otro,
        // sin transacción ni lock. Dos administradores configurando una
        // tarifa nueva casi al mismo tiempo podían terminar con dos filas
        // "activa = true" simultáneamente (ambas leen la misma tarifa
        // vigente antes de que cualquiera la desactive).
        //
        // Ahora todo el ciclo ocurre dentro de una transacción, bloqueando
        // (`lockForUpdate`) cualquier fila actualmente activa: la segunda
        // request espera a que la primera confirme y, al continuar, ya no
        // encuentra ninguna fila activa que bloquear, por lo que ambas
        // terminan aplicándose en serie y solo la última queda vigente.
        $nuevaTarifa = DB::transaction(function () use ($validated): Tarifa {
            Tarifa::query()
                ->where('activa', true)
                ->lockForUpdate()
                ->update([
                    'activa' => false,
                    'vigente_hasta' => now(),
                ]);

            return Tarifa::query()->create([
                'precio_base' => $validated['precio_base'],
                'precio_por_km' => $validated['precio_por_km'],
                'zona' => $validated['zona'] ?? null,
                'activa' => true,
                'vigente_desde' => now(),
            ]);
        });

        // Fase 4 (rendimiento): Tarifa::vigente() se cachea (ver el modelo);
        // hay que invalidar esa cache al cambiar la tarifa activa.
        Tarifa::olvidarVigenteEnCache();

        return response()->json([
            'message' => 'Nueva tarifa configurada exitosamente.',
            'data' => $nuevaTarifa,
        ], Response::HTTP_CREATED);
    }
}