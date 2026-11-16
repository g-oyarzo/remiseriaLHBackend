<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegistrarViajeTelefonicoRequest',
    type: 'object',
    description: 'Mismos campos que SolicitarViajeRequest, más el cliente (cliente_id o cliente) y una fecha_viaje opcional para registrar una reserva.',
    allOf: [new OA\Schema(ref: SolicitarViajeRequestSchema::class)],
    properties: [
        new OA\Property(property: 'cliente_id', type: 'integer', nullable: true, example: 42, description: 'Cliente existente. Excluyente con "cliente".'),
        new OA\Property(property: 'cliente', type: 'object', nullable: true, description: 'Cliente no registrado (o buscado por DNI).', properties: [
            new OA\Property(property: 'dni', type: 'string', example: '30123456'),
            new OA\Property(property: 'nombre', type: 'string', example: 'Ana'),
            new OA\Property(property: 'apellido', type: 'string', example: 'Pérez'),
            new OA\Property(property: 'telefono', type: 'string', example: '+54 221 555-1234'),
        ]),
        new OA\Property(property: 'fecha_viaje', type: 'string', format: 'date-time', nullable: true, description: 'Si se envía, el viaje se registra como reserva.'),
    ],
)]
final class RegistrarViajeTelefonicoRequestSchema {}
