<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\RolPersona;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MeResponse',
    type: 'object',
    description: 'GET /auth/me agrega el subtipo (cliente/conductor/administrador) según cuenta.rol.',
    properties: [
        new OA\Property(
            property: 'data',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'integer', example: 1),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'rol', ref: RolPersona::class),
                new OA\Property(property: 'persona', ref: PersonaSchema::class, nullable: true),
                new OA\Property(property: 'cliente', ref: ClienteSchema::class, nullable: true, description: 'Presente solo si rol = cliente.'),
                new OA\Property(property: 'conductor', ref: ConductorSchema::class, nullable: true, description: 'Presente solo si rol = conductor.'),
            ],
        ),
    ],
)]
final class MeResponse
{
}