<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\TipoViaje;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CrearViajeRequest',
    type: 'object',
    required: ['origen_lat', 'origen_lng', 'destino_lat', 'destino_lng'],
    properties: [
        new OA\Property(property: 'origen_lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9214),
        new OA\Property(property: 'origen_lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9544),
        new OA\Property(property: 'destino_lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9314),
        new OA\Property(property: 'destino_lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9644),
        new OA\Property(property: 'origen_localidad', type: 'string', maxLength: 100, nullable: true, example: 'La Plata'),
        new OA\Property(property: 'origen_calle', type: 'string', maxLength: 100, nullable: true, example: 'Calle 7'),
        new OA\Property(property: 'origen_numero', type: 'string', maxLength: 15, nullable: true, example: '800'),
        new OA\Property(property: 'tipo', ref: TipoViaje::class, description: 'Por defecto "actual" si se omite.'),
        new OA\Property(property: 'fecha_viaje', type: 'string', format: 'date-time', description: 'Requerida (>= ahora) solo si tipo = "programado".', example: '2026-09-02T09:00:00-03:00'),
    ],
)]
final class CrearViajeRequest
{
}
