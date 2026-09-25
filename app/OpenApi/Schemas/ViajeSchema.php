<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ViajeResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 100),
        new OA\Property(property: 'cliente_id', type: 'integer', example: 5),
        new OA\Property(property: 'conductor_id', type: 'integer', nullable: true, example: 8),
        new OA\Property(property: 'vehiculo_id', type: 'integer', nullable: true, example: 10),
        new OA\Property(property: 'tarifa_id', type: 'integer', nullable: true, example: 3),
        new OA\Property(property: 'origen', ref: CoordenadaSchema::class),
        new OA\Property(property: 'destino', ref: CoordenadaSchema::class),
        new OA\Property(property: 'origen_localidad', type: 'string', nullable: true, example: 'La Plata'),
        new OA\Property(property: 'origen_calle', type: 'string', nullable: true, example: 'Calle 7'),
        new OA\Property(property: 'origen_numero', type: 'string', nullable: true, example: '800'),
        new OA\Property(property: 'estado', ref: EstadoViaje::class),
        new OA\Property(property: 'tipo', ref: TipoViaje::class),
        new OA\Property(property: 'costo', type: 'string', nullable: true, example: '2450.00', description: 'Decimal serializado como string (cast decimal:2).'),
        new OA\Property(property: 'calificacion', type: 'integer', nullable: true, minimum: 1, maximum: 5, example: 5),
        new OA\Property(property: 'fecha_viaje', type: 'string', format: 'date-time', example: '2026-09-01T14:30:00-03:00'),
        new OA\Property(property: 'cliente', ref: ClienteSchema::class, nullable: true),
        new OA\Property(property: 'conductor', ref: ConductorSchema::class, nullable: true),
        new OA\Property(property: 'vehiculo', ref: VehiculoSchema::class, nullable: true),
        new OA\Property(property: 'tarifa', ref: TarifaSchema::class, nullable: true),
        new OA\Property(property: 'pago', ref: PagoSchema::class, nullable: true),
    ],
)]
final class ViajeSchema
{
}