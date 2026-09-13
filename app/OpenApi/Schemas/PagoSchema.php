<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoPago;
use App\Enums\MetodoPago;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PagoResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 42),
        new OA\Property(property: 'viaje_id', type: 'integer', example: 100),
        new OA\Property(property: 'metodo_pago', ref: MetodoPago::class),
        new OA\Property(property: 'monto', type: 'string', example: '2450.00', description: 'Decimal serializado como string (cast decimal:2).'),
        new OA\Property(property: 'estado', ref: EstadoPago::class),
        new OA\Property(property: 'fecha_pago', type: 'string', format: 'date-time', nullable: true),
    ],
)]
final class PagoSchema
{
}