<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Comprobante',
    type: 'object',
    description: 'Comprobante interno emitido (registro de solo lectura).',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'numero', type: 'string', example: '00000042'),
        new OA\Property(property: 'pago_id', type: 'integer', example: 42),
        new OA\Property(property: 'viaje_id', type: 'integer', example: 100),
        new OA\Property(property: 'total', type: 'string', example: '3250.00'),
        new OA\Property(property: 'metodo_pago', type: 'string', example: 'efectivo'),
        new OA\Property(property: 'cliente_nombre', type: 'string', nullable: true, example: 'Ana Pérez'),
        new OA\Property(property: 'conductor_nombre', type: 'string', nullable: true, example: 'Carlos Ruiz'),
        new OA\Property(property: 'emitido_en', type: 'string', format: 'date-time'),
        new OA\Property(property: 'sha256', type: 'string', example: 'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9', description: 'Hash del PDF guardado, para verificar su integridad.'),
    ],
)]
final class ComprobanteSchema {}
