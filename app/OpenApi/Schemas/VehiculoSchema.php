<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoVehiculo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MarcaResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'nombre', type: 'string', example: 'Chevrolet'),
    ],
)]
final class MarcaSchema
{
}

#[OA\Schema(
    schema: 'VehiculoResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 10),
        new OA\Property(property: 'marca_id', type: 'integer', example: 1),
        new OA\Property(property: 'modelo', type: 'string', example: 'Onix'),
        new OA\Property(property: 'patente', type: 'string', example: 'AB123CD'),
        new OA\Property(property: 'color', type: 'string', example: 'Blanco'),
        new OA\Property(property: 'anio', type: 'integer', example: 2022),
        new OA\Property(property: 'estado', ref: EstadoVehiculo::class),
        new OA\Property(property: 'marca', ref: MarcaSchema::class, nullable: true),
    ],
)]
final class VehiculoSchema
{
}
