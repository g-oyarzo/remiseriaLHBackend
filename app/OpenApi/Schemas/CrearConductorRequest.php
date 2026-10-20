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
        new OA\Property(
            property: 'vehiculo',
            type: 'object',
            nullable: true,
            description: 'Opcional. Crea el vehículo en la misma transacción. Excluyente con vehiculo_id.',
            required: ['marca_id', 'modelo', 'patente', 'color', 'anio'],
            properties: [
                new OA\Property(property: 'marca_id', type: 'integer', example: 1),
                new OA\Property(property: 'modelo', type: 'string', maxLength: 60, example: 'Etios'),
                new OA\Property(property: 'patente', type: 'string', maxLength: 10, example: 'AB123CD', description: 'Debe ser única.'),
                new OA\Property(property: 'color', type: 'string', maxLength: 30, example: 'Blanco'),
                new OA\Property(property: 'anio', type: 'integer', example: 2019),
            ],
        ),
    ],
)]
final class CrearConductorRequest
{
}
