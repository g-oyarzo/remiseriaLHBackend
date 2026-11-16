<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ZonaTarifariaRequest;
use App\Models\ZonaTarifaria;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\OpenApi\Schemas\ZonaTarifariaSchema;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zonas de contingencia del cotizador (CU 02, excepción 1b): montos fijos
 * por banda de distancia que se aplican cuando el servicio de mapas falla.
 */
class ZonaTarifariaController extends Controller
{
    #[OA\Get(
        path: '/admin/zonas-tarifarias',
        summary: 'Listar zonas tarifarias de contingencia',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: ZonaTarifariaSchema::class)),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ZonaTarifaria::query()->orderBy('distancia_max_km')->get(),
        ]);
    }

    #[OA\Post(
        path: '/admin/zonas-tarifarias',
        summary: 'Crear una zona tarifaria',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: ZonaTarifariaSchema::class)),
        responses: [
            new OA\Response(response: 201, description: 'Zona creada.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: ZonaTarifariaSchema::class)])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function store(ZonaTarifariaRequest $request): JsonResponse
    {
        $zona = ZonaTarifaria::query()->create($request->validated() + [
            'actualizada_por_persona_id' => $request->user()->persona_id,
        ]);

        return response()->json(['data' => $zona->refresh()], Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/admin/zonas-tarifarias/{zona}',
        summary: 'Modificar una zona tarifaria',
        description: 'Afecta solo a cotizaciones futuras: los viajes guardan el monto con el que fueron cotizados.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'zona', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: ZonaTarifariaSchema::class)),
        responses: [
            new OA\Response(response: 200, description: 'Zona actualizada.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: ZonaTarifariaSchema::class)])),
            new OA\Response(response: 404, description: 'Zona inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Error de validación.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function update(ZonaTarifariaRequest $request, ZonaTarifaria $zona): JsonResponse
    {
        $zona->update($request->validated() + [
            'actualizada_por_persona_id' => $request->user()->persona_id,
        ]);

        return response()->json(['data' => $zona->refresh()]);
    }

    #[OA\Delete(
        path: '/admin/zonas-tarifarias/{zona}',
        summary: 'Desactivar una zona tarifaria',
        description: 'Baja lógica: la zona deja de usarse en la contingencia pero se conserva.',
        tags: ['Tarifas'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'zona', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Zona desactivada.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: ZonaTarifariaSchema::class)])),
            new OA\Response(response: 404, description: 'Zona inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function destroy(ZonaTarifaria $zona): JsonResponse
    {
        $zona->update(['activa' => false]);

        return response()->json(['data' => $zona->refresh()]);
    }
}
