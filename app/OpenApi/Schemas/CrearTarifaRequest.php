<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CrearTarifaRequest',
    type: 'object',
    description: 'Crea una nueva versión vigente de la tarifa y cierra la anterior (vigente_hasta = now()). Montos > 0 con hasta dos decimales.',
    required: ['precio_base', 'precio_por_km'],
    properties: [
        new OA\Property(property: 'precio_base', type: 'number', format: 'float', minimum: 0, exclusiveMinimum: true, example: 1500.00),
        new OA\Property(property: 'precio_por_km', type: 'number', format: 'float', minimum: 0, exclusiveMinimum: true, example: 350.00),
        new OA\Property(property: 'zona', type: 'string', maxLength: 60, nullable: true, example: 'Casco urbano'),
    ],
)]
final class CrearTarifaRequest
{
}