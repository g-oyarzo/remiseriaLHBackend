<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\RolPersona;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PersonaResource',
    type: 'object',
    description: 'Datos personales compartidos por cliente, conductor y administrador.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'dni', type: 'string', example: '30123456'),
        new OA\Property(property: 'nombre', type: 'string', example: 'Juan'),
        new OA\Property(property: 'apellido', type: 'string', example: 'Pérez'),
        new OA\Property(property: 'telefono', type: 'string', nullable: true, example: '221-555-0100'),
    ],
)]
final class PersonaSchema
{
}

#[OA\Schema(
    schema: 'CuentaResource',
    type: 'object',
    description: 'Cuenta de acceso (autenticación). Es el modelo devuelto por Sanctum como "usuario".',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'persona_id', type: 'integer', example: 1),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'juan.perez@example.com'),
        new OA\Property(property: 'rol', ref: RolPersona::class),
        new OA\Property(property: 'persona', ref: PersonaSchema::class, nullable: true),
    ],
)]
final class CuentaSchema
{
}