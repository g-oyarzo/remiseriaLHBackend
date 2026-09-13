<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterRequest',
    type: 'object',
    description: 'Auto-registro: siempre crea una cuenta con rol "cliente". Los roles conductor y administrador solo pueden darse de alta por un administrador.',
    required: ['dni', 'nombre', 'apellido', 'email', 'password', 'password_confirmation'],
    properties: [
        new OA\Property(property: 'dni', type: 'string', maxLength: 15, example: '30123456'),
        new OA\Property(property: 'nombre', type: 'string', maxLength: 100, example: 'Juan'),
        new OA\Property(property: 'apellido', type: 'string', maxLength: 100, example: 'Pérez'),
        new OA\Property(property: 'telefono', type: 'string', maxLength: 30, nullable: true, example: '221-555-0100'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'juan.perez@example.com'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'contraseña-segura'),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'contraseña-segura'),
    ],
)]
final class RegisterRequest
{
}