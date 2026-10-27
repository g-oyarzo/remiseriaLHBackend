<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CotizarViajeRequest',
    type: 'object',
    required: ['origen_lat', 'origen_lng', 'destino_lat', 'destino_lng'],
    properties: [
        new OA\Property(property: 'origen_lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9214),
        new OA\Property(property: 'origen_lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9544),
        new OA\Property(property: 'destino_lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9055),
        new OA\Property(property: 'destino_lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9325),
    ],
)]
final class CotizarViajeRequestSchema {}
