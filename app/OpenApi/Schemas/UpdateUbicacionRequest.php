<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateUbicacionRequest',
    type: 'object',
    description: 'Cada actualización dispara (en cola) el evento UbicacionConductorActualizada por WebSockets, en el canal privado conductor.{conductorId} y, si hay un viaje activo, también en viaje.{viajeId}.',
    required: ['lat', 'lng'],
    properties: [
        new OA\Property(property: 'lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9214),
        new OA\Property(property: 'lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9544),
    ],
)]
final class UpdateUbicacionRequest
{
}