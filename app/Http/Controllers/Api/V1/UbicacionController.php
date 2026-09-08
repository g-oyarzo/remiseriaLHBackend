<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\RolPersona;
use App\Http\Controllers\Controller;
use App\Models\Conductor;
use App\ValueObjects\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UbicacionController extends Controller
{
    /**
     * PATCH /api/v1/conductor/ubicacion
     *
     * Actualiza la ubicación actual (GPS) del conductor (RNF02).
     */
    public function update(Request $request): JsonResponse
    {
        $cuenta = $request->user();

        if ($cuenta->rol !== RolPersona::Conductor) {
            return response()->json([
                'message' => 'Solo los conductores pueden actualizar su ubicación.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $conductor = Conductor::query()->find($cuenta->persona_id);

        if (! $conductor) {
            return response()->json([
                'message' => 'Conductor no encontrado.',
            ], Response::HTTP_NOT_FOUND);
        }

        $conductor->update([
            'ubicacion_actual' => new Coordinate(
                lat: (float) $validated['lat'],
                lng: (float) $validated['lng']
            ),
        ]);

        return response()->json([
            'message' => 'Ubicación actualizada correctamente.',
        ]);
    }
}
