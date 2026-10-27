<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoViaje;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReporteRequest;
use App\OpenApi\Schemas\ErrorResponse;
use App\OpenApi\Schemas\ValidationErrorResponse;
use App\Services\Reportes\ExportadorReportes;
use App\Services\Reportes\ReportesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Reportes administrativos (CU 15, CU 16).
 */
class ReporteController extends Controller
{
    public function __construct(private readonly ReportesService $reportes) {}

    #[OA\Get(
        path: '/admin/reportes/estadisticas',
        summary: 'Reporte estadístico de viajes',
        description: 'Totales por estado, viajes finalizados y cancelados, facturación (solo pagos confirmados), cobros pendientes de conciliación, ticket promedio y distancia total. El rango de fechas no puede superar REPORTES_RANGO_MAXIMO_DIAS.',
        tags: ['Reportes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'fecha_desde', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'fecha_hasta', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'conductor_dni', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'estado', in: 'query', required: false, schema: new OA\Schema(ref: EstadoViaje::class)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK.', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'filtros', type: 'object'),
                    new OA\Property(property: 'total_viajes', type: 'integer', example: 1520),
                    new OA\Property(property: 'por_estado', type: 'object', example: ['finalizado' => 1300, 'cancelado' => 120]),
                    new OA\Property(property: 'viajes_finalizados', type: 'integer', example: 1300),
                    new OA\Property(property: 'viajes_cancelados', type: 'integer', example: 120),
                    new OA\Property(property: 'facturacion', type: 'string', example: '4521300.50'),
                    new OA\Property(property: 'pagos_confirmados', type: 'integer', example: 1280),
                    new OA\Property(property: 'cobros_pendientes', type: 'string', example: '15300.00'),
                    new OA\Property(property: 'ticket_promedio', type: 'string', nullable: true, example: '3532.27'),
                    new OA\Property(property: 'distancia_total_km', type: 'number', format: 'float', example: 7120.5),
                ]),
            ])),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Rango de fechas inválido o demasiado amplio, DNI o estado inválidos.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
        ],
    )]
    public function estadisticas(ReporteRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->reportes->estadisticas($request->filtros())]);
    }

    #[OA\Get(
        path: '/admin/reportes/exportar',
        summary: 'Exportar el reporte en PDF o Excel',
        description: 'Mismos filtros que /admin/reportes/estadisticas más el formato. Incluye el resumen y el detalle de viajes (hasta REPORTES_MAX_FILAS_EXPORTACION filas; si hay más se responde 422 para que se acote el rango). El archivo se genera en el momento y no queda almacenado.',
        tags: ['Reportes'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'formato', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['pdf', 'excel'])),
            new OA\Parameter(name: 'fecha_desde', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'fecha_hasta', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'conductor_dni', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'estado', in: 'query', required: false, schema: new OA\Schema(ref: EstadoViaje::class)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Archivo del reporte.', content: [
                new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
                new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', schema: new OA\Schema(type: 'string', format: 'binary')),
            ]),
            new OA\Response(response: 403, description: 'La cuenta autenticada no tiene rol "administrador".', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 422, description: 'Formato inválido, filtros inválidos o demasiadas filas para exportar.', content: new OA\JsonContent(ref: ValidationErrorResponse::class)),
            new OA\Response(response: 429, description: 'Demasiadas exportaciones en poco tiempo.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function exportar(ReporteRequest $request, ExportadorReportes $exportador): Response|JsonResponse
    {
        $formato = $request->validate([
            'formato' => ['required', Rule::in(['pdf', 'excel'])],
        ])['formato'];

        $filtros = $request->filtros();
        $limite = (int) config('remiseria.reportes.max_filas_exportacion');
        $cantidad = $this->reportes->contar($filtros);

        if ($cantidad > $limite) {
            return response()->json([
                'message' => "El reporte tiene {$cantidad} viajes y el máximo exportable es {$limite}. Acote el rango de fechas o los filtros.",
                'errors' => ['fecha_desde' => ['Demasiados registros para exportar.']],
            ], HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
        }

        $estadisticas = $this->reportes->estadisticas($filtros);
        $viajes = $this->reportes->detalle($filtros, $limite);
        $nombre = "reporte-viajes-{$filtros->desde->toDateString()}-{$filtros->hasta->toDateString()}";

        [$contenido, $tipo, $extension] = $formato === 'pdf'
            ? [$exportador->pdf($estadisticas, $viajes), 'application/pdf', 'pdf']
            : [$exportador->excel($estadisticas, $viajes), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'];

        return response($contenido, HttpStatus::HTTP_OK, [
            'Content-Type' => $tipo,
            'Content-Disposition' => "attachment; filename=\"{$nombre}.{$extension}\"",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
