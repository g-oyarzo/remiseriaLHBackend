<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EnviarMensajeRequest',
    type: 'object',
    required: ['contenido'],
    properties: [
        new OA\Property(property: 'contenido', type: 'string', maxLength: 1000, example: 'Estoy en la esquina.'),
    ],
)]
final class EnviarMensajeRequest
{
}