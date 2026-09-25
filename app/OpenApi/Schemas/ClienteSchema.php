<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ClienteResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'persona_id', type: 'integer', example: 5, description: 'Clave primaria; coincide con personas.id.'),
        new OA\Property(property: 'persona', ref: PersonaSchema::class, nullable: true),
    ],
)]
final class ClienteSchema
{
}
