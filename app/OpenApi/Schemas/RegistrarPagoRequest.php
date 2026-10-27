<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\MetodoPago;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegistrarPagoRequest',
    type: 'object',
    required: ['metodo_pago', 'monto'],
    properties: [
        new OA\Property(property: 'metodo_pago', ref: MetodoPago::class),
        new OA\Property(property: 'monto', type: 'number', format: 'float', minimum: 0, exclusiveMinimum: true, example: 2450.00, description: 'Debe ser exactamente el importe del viaje (costo_final).'),
        new OA\Property(property: 'referencia_externa', type: 'string', pattern: '^[A-Za-z0-9._-]{4,100}$', nullable: true, example: 'MP-123456789', description: 'Obligatoria para qr, tarjetaDebito y tarjetaCredito; no se admite para efectivo. No puede repetirse entre pagos del mismo método.'),
    ],
)]
final class RegistrarPagoRequest
{
}