<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CalificarViajeRequest',
    type: 'object',
    required: ['calificacion'],
    properties: [
        new OA\Property(property: 'calificacion', type: 'integer', minimum: 1, maximum: 5, example: 5),
    ],
)]
final class CalificarViajeRequest
{
}
