<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoConductor;
use App\Http\Controllers\Controller;
use App\Models\Conductor;
use App\ValueObjects\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

class ConductorController extends Controller
{
    /**
     * GET /api/v1/admin/conductores
     *
     * Listado para administradores.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Conductor::query()->with(['persona.cuenta', 'vehiculo.marca']);

        if ($request->has('estado')) {
            $estado = EstadoConductor::tryFrom($request->input('estado'));
            if ($estado) {
                $query->where('estado', $estado);
            }
        }

        $conductores = $query->paginate((int) $request->input('per_page', 15));

        return response()->json($conductores);
    }

    /**
     * GET /api/v1/admin/conductores/{conductor}
     */
    public function show(Conductor $conductor): JsonResponse
    {
        $conductor->load(['persona.cuenta', 'vehiculo.marca', 'viajes' => function ($query) {
            $query->latest('fecha_viaje')->limit(5);
        }]);

        return response()->json(['data' => $conductor]);
    }

    /**
     * GET /api/v1/conductores/cercanos
     *
     * Busca conductores activos y en servicio cerca de una ubicación (para despachador).
     */
    public function cercanos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radio_metros' => ['nullable', 'numeric', 'min:100', 'max:50000'],
        ]);

        $origen = new Coordinate(
            lat: (float) $validated['lat'],
            lng: (float) $validated['lng']
        );

        $radio = isset($validated['radio_metros']) ? (float) $validated['radio_metros'] : 5000.0;

        $conductores = Conductor::query()
            ->with(['persona', 'vehiculo.marca'])
            ->disponibles()
            ->cercanos($origen, $radio)
            ->get();

        return response()->json(['data' => $conductores]);
    }

    /**
     * PATCH /api/v1/conductor/estado-servicio
     *
     * El propio conductor alterna su estado de en_servicio.
     */
    public function toggleServicio(Request $request): JsonResponse
    {
        $cuenta = $request->user();
        
        $conductor = Conductor::query()->findOrFail($cuenta->persona_id);

        if ($conductor->estado === EstadoConductor::Eliminado) {
            return response()->json([
                'message' => 'No puede ponerse en servicio porque su cuenta está eliminada.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'en_servicio' => ['required', 'boolean'],
        ]);

        $conductor->update(['en_servicio' => $validated['en_servicio']]);

        return response()->json([
            'message' => 'Estado de servicio actualizado.',
            'data' => $conductor,
        ]);
    }
}
