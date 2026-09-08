<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoVehiculo;
use App\Http\Controllers\Controller;
use App\Models\Vehiculo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\Response;

class VehiculoController extends Controller
{
    /**
     * GET /api/v1/admin/vehiculos
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vehiculo::query()->with(['marca']);

        if ($request->has('estado')) {
            $estado = EstadoVehiculo::tryFrom($request->input('estado'));
            if ($estado) {
                $query->where('estado', $estado);
            }
        }

        $vehiculos = $query->paginate((int) $request->input('per_page', 15));

        return response()->json($vehiculos);
    }

    /**
     * POST /api/v1/admin/vehiculos
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'marca_id' => ['required', 'exists:marcas,id'],
            'modelo' => ['required', 'string', 'max:60'],
            'patente' => ['required', 'string', 'max:10', 'unique:vehiculos,patente'],
            'color' => ['required', 'string', 'max:30'],
            'anio' => ['required', 'integer', 'min:2000', 'max:' . (date('Y') + 1)],
            'estado' => ['nullable', new Enum(EstadoVehiculo::class)],
        ]);

        $vehiculo = Vehiculo::query()->create([
            'marca_id' => $validated['marca_id'],
            'modelo' => $validated['modelo'],
            'patente' => $validated['patente'],
            'color' => $validated['color'],
            'anio' => $validated['anio'],
            'estado' => $validated['estado'] ?? EstadoVehiculo::Operando,
        ]);

        $vehiculo->load('marca');

        return response()->json([
            'message' => 'Vehículo creado exitosamente.',
            'data' => $vehiculo,
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/admin/vehiculos/{vehiculo}
     */
    public function show(Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->load(['marca', 'conductores.persona']);

        return response()->json(['data' => $vehiculo]);
    }

    /**
     * PUT /api/v1/admin/vehiculos/{vehiculo}
     */
    public function update(Request $request, Vehiculo $vehiculo): JsonResponse
    {
        $validated = $request->validate([
            'marca_id' => ['sometimes', 'exists:marcas,id'],
            'modelo' => ['sometimes', 'string', 'max:60'],
            'patente' => ['sometimes', 'string', 'max:10', 'unique:vehiculos,patente,' . $vehiculo->id],
            'color' => ['sometimes', 'string', 'max:30'],
            'anio' => ['sometimes', 'integer', 'min:2000', 'max:' . (date('Y') + 1)],
            'estado' => ['sometimes', new Enum(EstadoVehiculo::class)],
        ]);

        $vehiculo->update($validated);

        return response()->json([
            'message' => 'Vehículo actualizado exitosamente.',
            'data' => $vehiculo,
        ]);
    }
}
