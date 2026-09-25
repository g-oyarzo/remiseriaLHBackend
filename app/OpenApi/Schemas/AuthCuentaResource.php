<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\RolPersona;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AuthCuentaResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'juan.perez@example.com'),
        new OA\Property(property: 'rol', ref: RolPersona::class),
        new OA\Property(property: 'persona', ref: PersonaSchema::class, nullable: true),
    ],
)]
final class AuthCuentaResource
{
}