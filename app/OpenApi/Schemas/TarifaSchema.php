<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TarifaResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 3),
        new OA\Property(property: 'precio_base', type: 'string', example: '1500.00', description: 'Decimal serializado como string (cast decimal:2 de Laravel).'),
        new OA\Property(property: 'precio_por_km', type: 'string', example: '350.00'),
        new OA\Property(property: 'zona', type: 'string', nullable: true, example: 'Casco urbano'),
        new OA\Property(property: 'activa', type: 'boolean', example: true),
        new OA\Property(property: 'vigente_desde', type: 'string', format: 'date-time', example: '2026-01-01T00:00:00-03:00'),
        new OA\Property(property: 'vigente_hasta', type: 'string', format: 'date-time', nullable: true),
    ],
)]
final class TarifaSchema
{
}