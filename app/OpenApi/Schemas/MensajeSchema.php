<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'MensajeResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 501),
        new OA\Property(property: 'viaje_id', type: 'integer', example: 100),
        new OA\Property(property: 'emisor_persona_id', type: 'integer', example: 5),
        new OA\Property(property: 'receptor_persona_id', type: 'integer', example: 8),
        new OA\Property(property: 'contenido', type: 'string', example: 'Estoy en la esquina.'),
        new OA\Property(property: 'leido', type: 'boolean', example: false),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'emisor', ref: PersonaSchema::class, nullable: true),
        new OA\Property(property: 'receptor', ref: PersonaSchema::class, nullable: true),
    ],
)]
final class MensajeSchema
{
}
