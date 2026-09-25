<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Punto geográfico WGS84 (SRID 4326). Es la forma JSON de App\ValueObjects\Coordinate,
 * usada para `viajes.origen`, `viajes.destino` y `conductores.ubicacion_actual`.
 */
#[OA\Schema(
    schema: 'CoordenadaSchema',
    type: 'object',
    properties: [
        new OA\Property(property: 'lat', type: 'number', format: 'float', example: -34.9214),
        new OA\Property(property: 'lng', type: 'number', format: 'float', example: -57.9544),
    ],
)]
final class CoordenadaSchema
{
}