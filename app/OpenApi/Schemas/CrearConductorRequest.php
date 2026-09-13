<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CrearConductorRequest',
    type: 'object',
    description: 'Alta de un conductor por un administrador (HALL-011: antes no existía ningún endpoint para esto). Crea Persona + Conductor + Cuenta (rol "conductor") en una única transacción.',
    required: ['dni', 'nombre', 'apellido', 'email', 'password', 'cuil', 'fecha_nacimiento', 'domicilio_localidad', 'domicilio_calle', 'domicilio_numero'],
    properties: [
        new OA\Property(property: 'dni', type: 'string', maxLength: 15, example: '30123456'),
        new OA\Property(property: 'nombre', type: 'string', maxLength: 100, example: 'Marcos'),
        new OA\Property(property: 'apellido', type: 'string', maxLength: 100, example: 'Gómez'),
        new OA\Property(property: 'telefono', type: 'string', maxLength: 30, nullable: true, example: '221-555-0199'),
        new OA\Property(property: 'email', type: 'string', format: 'email', maxLength: 255, example: 'marcos.gomez@example.com'),
        new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'contraseña-temporal-segura'),
        new OA\Property(property: 'cuil', type: 'string', maxLength: 15, example: '20301234567'),
        new OA\Property(property: 'fecha_nacimiento', type: 'string', format: 'date', example: '1990-05-12'),
        new OA\Property(property: 'domicilio_localidad', type: 'string', maxLength: 100, example: 'La Plata'),
        new OA\Property(property: 'domicilio_calle', type: 'string', maxLength: 100, example: 'Calle 50'),
        new OA\Property(property: 'domicilio_numero', type: 'string', maxLength: 15, example: '1234'),
        new OA\Property(property: 'vehiculo_id', type: 'integer', nullable: true, example: 10, description: 'Opcional. Debe ser un vehículo existente y sin otro conductor asignado.'),
    ],
)]
final class CrearConductorRequest
{
}
