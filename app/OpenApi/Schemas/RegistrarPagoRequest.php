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
        new OA\Property(property: 'monto', type: 'number', format: 'float', minimum: 0, example: 2450.00),
    ],
)]
final class RegistrarPagoRequest
{
}