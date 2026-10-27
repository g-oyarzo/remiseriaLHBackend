<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\MetodoPago;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SolicitarViajeRequest',
    type: 'object',
    required: ['origen_lat', 'origen_lng', 'destino_lat', 'destino_lng', 'metodo_pago'],
    properties: [
        new OA\Property(property: 'origen_lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9214),
        new OA\Property(property: 'origen_lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9544),
        new OA\Property(property: 'destino_lat', type: 'number', format: 'float', minimum: -90, maximum: 90, example: -34.9055),
        new OA\Property(property: 'destino_lng', type: 'number', format: 'float', minimum: -180, maximum: 180, example: -57.9325),
        new OA\Property(property: 'metodo_pago', ref: MetodoPago::class),
        new OA\Property(property: 'origen_localidad', type: 'string', maxLength: 100, nullable: true, example: 'La Plata'),
        new OA\Property(property: 'origen_calle', type: 'string', maxLength: 100, nullable: true, example: 'Calle 7'),
        new OA\Property(property: 'origen_numero', type: 'string', maxLength: 15, nullable: true, example: '800'),
        new OA\Property(property: 'destino_localidad', type: 'string', maxLength: 100, nullable: true, example: 'La Plata'),
        new OA\Property(property: 'destino_calle', type: 'string', maxLength: 100, nullable: true, example: 'Calle 50'),
        new OA\Property(property: 'destino_numero', type: 'string', maxLength: 15, nullable: true, example: '1200'),
        new OA\Property(property: 'monto_confirmado', type: 'number', format: 'float', nullable: true, example: 2450.00, description: 'Opcional. Monto que el cliente aceptó al cotizar; si no coincide con la cotización vigente se responde 409 con la nueva cotización.'),
    ],
)]
final class SolicitarViajeRequestSchema {}
