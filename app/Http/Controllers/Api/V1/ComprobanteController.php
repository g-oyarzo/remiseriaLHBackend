<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoPago;
use App\Http\Controllers\Controller;
use App\Models\Comprobante;
use App\Models\Viaje;
use App\OpenApi\Schemas\ErrorResponse;
use App\Services\Comprobantes\ComprobanteNoDisponible;
use App\Services\Comprobantes\EmisionDeComprobantes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

class ComprobanteController extends Controller
{
    /**
     * GET /api/v1/viajes/{viaje}/comprobante (CU 17)
     */
    #[OA\Get(
        path: '/viajes/{viaje}/comprobante',
        summary: 'Descargar el comprobante PDF del viaje',
        description: 'Solo el cliente del viaje, el conductor asignado o un administrador, y solo con el pago confirmado. Entrega el comprobante emitido al confirmarse el pago (registro inmutable, verificado por SHA-256); no incluye DNI, teléfono ni domicilio. Es un comprobante interno: no reemplaza a una factura electrónica.',
        tags: ['Pagos'],
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'viaje', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'PDF del comprobante.', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 403, description: 'No tiene acceso a este viaje.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 404, description: 'Viaje inexistente.', content: new OA\JsonContent(ref: ErrorResponse::class)),
            new OA\Response(response: 409, description: 'El viaje no tiene un pago confirmado, o el archivo no supera la verificación de integridad.', content: new OA\JsonContent(ref: ErrorResponse::class)),
        ],
    )]
    public function show(Viaje $viaje, EmisionDeComprobantes $emision): Response|JsonResponse
    {
        $this->authorize('ver', $viaje);

        if ($viaje->pago?->estado !== EstadoPago::Confirmado) {
            return response()->json([
                'message' => 'El comprobante está disponible cuando el pago del viaje está confirmado.',
            ], HttpStatus::HTTP_CONFLICT);
        }

        try {
            // Normalmente ya fue emitido al confirmarse el pago; si la cola se
            // demoró, se emite ahora (es idempotente).
            $comprobante = $emision->emitir($viaje->pago->id);

            return self::descarga($comprobante, $emision->contenido($comprobante));
        } catch (ComprobanteNoDisponible $e) {
            return response()->json(['message' => $e->getMessage()], HttpStatus::HTTP_CONFLICT);
        }
    }

    public static function descarga(Comprobante $comprobante, string $contenido): Response
    {
        return response($contenido, HttpStatus::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"comprobante-{$comprobante->numero}-viaje-{$comprobante->viaje_id}.pdf\"",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
