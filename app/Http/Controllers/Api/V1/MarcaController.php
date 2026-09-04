<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Marca;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\MarcaSchema;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class MarcaController extends Controller
{
    /**
     * GET /api/v1/marcas
     */
    #[OA\Get(
        path: '/marcas',
        summary: 'Listar marcas de vehículos',
        description: 'Catálogo completo (sin paginar), ordenado alfabéticamente. Accesible a cualquier rol autenticado.',
        tags: ['Marcas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: MarcaSchema::class)),
            ])),
            new OA\Response(response: 401, description: 'No autenticado.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(): JsonResponse
    {
        $marcas = Marca::query()->ordenadasPorNombre()->get();

        return response()->json(['data' => $marcas]);
    }
}