<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoVehiculo;
use App\Http\Controllers\Controller;
use App\Models\Vehiculo;
use App\OpenApi\Schemas\ActualizarVehiculoRequest as ActualizarVehiculoRequestSchema;
use App\OpenApi\Schemas\CrearVehiculoRequest as CrearVehiculoRequestSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\OpenApi\Schemas\VehiculoSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class VehiculoController extends Controller
{
    /**
     * GET /api/v1/admin/vehiculos
     */
    #[OA\Get(
        path: '/admin/vehiculos',
        summary: 'Listar vehículos',
        tags: ['Admin - Vehículos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'estado', in: 'query', required: false, schema: new OA\Schema(ref: EstadoVehiculo::class)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'OK.',
                content: new OA\JsonContent(
                    allOf: [
                        new OA\Schema(ref: PaginationMeta::class),
                        new OA\Schema(properties: [
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: VehiculoSchema::class)),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
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
    #[OA\Post(
        path: '/admin/vehiculos',
        summary: 'Crear un vehículo',
        tags: ['Admin - Vehículos'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: CrearVehiculoRequestSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Vehículo creado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Vehículo creado exitosamente.'),
                new OA\Property(property: 'data', ref: VehiculoSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación (marca inexistente, patente duplicada, etc.).', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
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
    #[OA\Get(
        path: '/admin/vehiculos/{vehiculo}',
        summary: 'Detalle de un vehículo',
        description: 'Incluye la marca y los conductores que tienen este vehículo asignado.',
        tags: ['Admin - Vehículos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'vehiculo', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: VehiculoSchema::class)])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Vehículo inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function show(Vehiculo $vehiculo): JsonResponse
    {
        $vehiculo->load(['marca', 'conductores.persona']);

        return response()->json(['data' => $vehiculo]);
    }

    /**
     * PUT /api/v1/admin/vehiculos/{vehiculo}
     */
    #[OA\Put(
        path: '/admin/vehiculos/{vehiculo}',
        summary: 'Actualizar un vehículo',
        tags: ['Admin - Vehículos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'vehiculo', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: ActualizarVehiculoRequestSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Vehículo actualizado.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Vehículo actualizado exitosamente.'),
                new OA\Property(property: 'data', ref: VehiculoSchema::class),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Vehículo inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
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