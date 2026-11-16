<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateUbicacionRequest',
    type: 'object',
    description: 'Cada actualización dispara (en cola) el evento UbicacionConductorActualizada ("ubicacion.actualizada") por WebSockets, en los canales privados conductor.{conductorId} y admin.despacho y, si hay un viaje activo, también en viaje.{viajeId}. El payload incluye registrado_en.',
    required: ['lat', 'lng'],
    properties: [
        new OA\Property(property: 'lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9214),
        new OA\Property(property: 'lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9544),
        new OA\Property(property: 'precision_metros', type: 'number', format: 'float', minimum: 0, maximum: 10000, nullable: true, example: 8.5, description: 'Precisión informada por el GPS del dispositivo. Pings menos precisos que UBICACION_PRECISION_MAXIMA_METROS no suman al recorrido del viaje.'),
    ],
)]
final class UpdateUbicacionRequest
{
}