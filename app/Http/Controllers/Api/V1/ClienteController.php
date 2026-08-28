<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    /**
     * GET /api/v1/admin/clientes
     */
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
    public function show(Cliente $cliente): JsonResponse
    {
        $cliente->load(['persona.cuenta', 'viajes' => function ($query) {
            $query->latest('fecha_viaje')->limit(5);
        }]);

        return response()->json(['data' => $cliente]);
    }
}
