<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RefreshTokenResponse',
    type: 'object',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Token renovado exitosamente.'),
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'token', type: 'string', example: '2|newTokenAbc123...'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
            ],
        ),
    ],
)]
final class RefreshTokenResponse
{
}
