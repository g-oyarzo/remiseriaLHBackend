<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tarifa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TarifaController extends Controller
{
    /**
     * GET /api/v1/admin/tarifas
     */
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
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'precio_base' => ['required', 'numeric', 'min:0'],
            'precio_por_km' => ['required', 'numeric', 'min:0'],
            'zona' => ['nullable', 'string', 'max:60'],
        ]);

        // Desactivar la tarifa vigente actualmente
        $tarifaAnterior = Tarifa::vigente();
        if ($tarifaAnterior) {
            $tarifaAnterior->update([
                'activa' => false,
                'vigente_hasta' => now(),
            ]);
        }

        $nuevaTarifa = Tarifa::query()->create([
            'precio_base' => $validated['precio_base'],
            'precio_por_km' => $validated['precio_por_km'],
            'zona' => $validated['zona'] ?? null,
            'activa' => true,
            'vigente_desde' => now(),
        ]);

        return response()->json([
            'message' => 'Nueva tarifa configurada exitosamente.',
            'data' => $nuevaTarifa,
        ], Response::HTTP_CREATED);
    }
}
