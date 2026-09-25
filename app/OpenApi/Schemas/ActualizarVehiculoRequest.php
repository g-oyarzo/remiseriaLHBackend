<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoVehiculo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ActualizarVehiculoRequest',
    type: 'object',
    description: 'Todos los campos son opcionales (actualización parcial).',
    properties: [
        new OA\Property(property: 'marca_id', type: 'integer', example: 1),
        new OA\Property(property: 'modelo', type: 'string', maxLength: 60, example: 'Onix'),
        new OA\Property(property: 'patente', type: 'string', maxLength: 10, example: 'AB123CD'),
        new OA\Property(property: 'color', type: 'string', maxLength: 30, example: 'Blanco'),
        new OA\Property(property: 'anio', type: 'integer', minimum: 2000, example: 2022),
        new OA\Property(property: 'estado', ref: EstadoVehiculo::class),
    ],
)]
final class ActualizarVehiculoRequest
{
}