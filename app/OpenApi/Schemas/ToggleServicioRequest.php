<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ToggleServicioRequest',
    type: 'object',
    required: ['en_servicio'],
    properties: [
        new OA\Property(property: 'en_servicio', type: 'boolean', example: true),
    ],
)]
final class ToggleServicioRequest
{
}