<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\OpenApi\Schemas\ClienteSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ClienteController extends Controller
{
    /**
     * GET /api/v1/admin/clientes
     */
    #[OA\Get(
        path: '/admin/clientes',
        summary: 'Listar clientes',
        tags: ['Admin - Clientes'],
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
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: ClienteSchema::class)),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $clientes = Cliente::query()
            ->with('persona.cuenta')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json($clientes);
    }

    /**
     * GET /api/v1/admin/clientes/{cliente}
     */
    #[OA\Get(
        path: '/admin/clientes/{cliente}',
        summary: 'Detalle de un cliente',
        description: 'Incluye sus últimos 5 viajes (por fecha_viaje).',
        tags: ['Admin - Clientes'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'cliente', in: 'path', required: true, description: 'persona_id del cliente.', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: ClienteSchema::class)])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Cliente inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function show(Cliente $cliente): JsonResponse
    {
        $cliente->load(['persona.cuenta', 'viajes' => function ($query) {
            $query->latest('fecha_viaje')->limit(5);
        }]);

        return response()->json(['data' => $cliente]);
    }
}