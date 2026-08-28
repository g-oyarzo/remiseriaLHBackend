<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Marca;
use Illuminate\Http\JsonResponse;

class MarcaController extends Controller
{
    /**
     * GET /api/v1/marcas
     */
    public function index(): JsonResponse
    {
        $marcas = Marca::query()->ordenadasPorNombre()->get();

        return response()->json(['data' => $marcas]);
    }
}
