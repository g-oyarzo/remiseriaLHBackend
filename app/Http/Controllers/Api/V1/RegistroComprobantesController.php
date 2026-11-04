<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\MetodoPago;
use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\OpenApi\Schemas\ComprobanteSchema;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\PaginationMeta;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\Services\Comprobantes\ComprobanteNoDisponible;
use App\Services\Comprobantes\EmisionDeComprobantes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Registro de comprobantes emitidos para la administración: solo consulta y
 * descarga (decisión del negocio). No hay rutas para modificar ni eliminar.
 */
class RegistroComprobantesController extends Controller
{
    #[OA\Get(
        path: '/admin/comprobantes',
        summary: 'Registro de comprobantes emitidos',
        description: 'Solo lectura. Filtros opcionales por fecha de emisión, número, viaje y método de pago.',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'fecha_desde', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'fecha_hasta', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'numero', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'viaje_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'metodo_pago', in: 'query', required: false, schema: new OA\Schema(ref: MetodoPago::class)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Entre 1 y 100.', schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(allOf: [
                new OA\Schema(ref: PaginationMeta::class),
                new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: ComprobanteSchema::class))]),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Filtros inválidos.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_desde'],
            'numero' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
            'viaje_id' => ['nullable', 'integer'],
            'metodo_pago' => ['nullable', Rule::enum(MetodoPago::class)],
        ]);

        $comprobantes = Comprobante::query()
            ->when($filtros['fecha_desde'] ?? null, fn ($q, $f) => $q->where('emitido_en', '>=', $f.' 00:00:00'))
            ->when($filtros['fecha_hasta'] ?? null, fn ($q, $f) => $q->where('emitido_en', '<=', $f.' 23:59:59'))
            ->when($filtros['numero'] ?? null, fn ($q, $n) => $q->where('numero', sprintf('%08d', (int) $n)))
            ->when($filtros['viaje_id'] ?? null, fn ($q, $id) => $q->where('viaje_id', $id))
            ->when($filtros['metodo_pago'] ?? null, fn ($q, $m) => $q->where('metodo_pago', $m))
            ->orderByDesc('emitido_en')
            ->orderByDesc('id')
            ->paginate($this->porPagina($request));

        return response()->json($comprobantes);
    }

    #[OA\Get(
        path: '/admin/comprobantes/{comprobante}',
        summary: 'Detalle de un comprobante del registro',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'comprobante', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: ComprobanteSchema::class)])),
            new OA\Response(response: 404, description: 'Comprobante inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function show(Comprobante $comprobante): JsonResponse
    {
        return response()->json(['data' => $comprobante]);
    }

    #[OA\Get(
        path: '/admin/comprobantes/{comprobante}/descargar',
        summary: 'Descargar el PDF de un comprobante del registro',
        description: 'Entrega el archivo guardado al emitirlo, verificado contra su SHA-256.',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'comprobante', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF.', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 404, description: 'Comprobante inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El archivo falta o no supera la verificación de integridad.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function descargar(Comprobante $comprobante, EmisionDeComprobantes $emision): Response|JsonResponse
    {
        try {
            return ComprobanteController::descarga($comprobante, $emision->contenido($comprobante));
        } catch (ComprobanteNoDisponible $e) {
            return response()->json(['message' => $e->getMessage()], HttpStatus::HTTP_CONFLICT);
        }
    }
}
