<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\MetodoCotizacion;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Cotizacion',
    type: 'object',
    properties: [
        new OA\Property(property: 'monto', type: 'string', example: '4317.50', description: 'Costo estimado en pesos, con dos decimales.'),
        new OA\Property(property: 'distancia_km', type: 'number', format: 'float', example: 7.65),
        new OA\Property(property: 'duracion_minutos', type: 'integer', nullable: true, example: 14, description: 'Null cuando se usó la contingencia (sin ruteo no hay tiempo estimado).'),
        new OA\Property(property: 'metodo', ref: MetodoCotizacion::class),
        new OA\Property(property: 'tarifa', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'integer', example: 3),
            new OA\Property(property: 'precio_base', type: 'string', example: '1500.00'),
            new OA\Property(property: 'precio_por_km', type: 'string', example: '350.00'),
        ]),
        new OA\Property(property: 'zona_tarifaria_id', type: 'integer', nullable: true, example: null),
    ],
)]
final class CotizacionSchema {}
