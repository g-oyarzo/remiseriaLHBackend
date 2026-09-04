<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoConductor;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ConductorResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'persona_id', type: 'integer', example: 8, description: 'Clave primaria; coincide con personas.id.'),
        new OA\Property(property: 'cuil', type: 'string', example: '20301234567'),
        new OA\Property(property: 'fecha_nacimiento', type: 'string', format: 'date', example: '1990-05-12'),
        new OA\Property(property: 'domicilio_localidad', type: 'string', example: 'La Plata'),
        new OA\Property(property: 'domicilio_calle', type: 'string', example: 'Calle 50'),
        new OA\Property(property: 'domicilio_numero', type: 'string', example: '1234'),
        new OA\Property(property: 'foto', type: 'string', format: 'uri', nullable: true),
        new OA\Property(property: 'calificacion', type: 'number', format: 'float', example: 4.8),
        new OA\Property(property: 'estado', ref: EstadoConductor::class),
        new OA\Property(property: 'en_servicio', type: 'boolean', example: true),
        new OA\Property(property: 'ubicacion_actual', ref: CoordenadaSchema::class, nullable: true),
        new OA\Property(property: 'vehiculo_id', type: 'integer', nullable: true, example: 10),
        new OA\Property(property: 'persona', ref: PersonaSchema::class, nullable: true),
        new OA\Property(property: 'vehiculo', ref: VehiculoSchema::class, nullable: true),
    ],
)]
final class ConductorSchema
{
}
