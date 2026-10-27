<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ZonaTarifaria',
    type: 'object',
    description: 'Banda de distancia (en línea recta) con monto fijo, usada como contingencia cuando el servicio de mapas no responde.',
    required: ['nombre', 'distancia_max_km', 'monto'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', readOnly: true, example: 1),
        new OA\Property(property: 'nombre', type: 'string', maxLength: 60, example: 'Casco urbano'),
        new OA\Property(property: 'distancia_max_km', type: 'string', example: '5.00', description: 'Límite superior inclusive de la banda, en km.'),
        new OA\Property(property: 'monto', type: 'string', example: '3500.00', description: 'Monto fijo en pesos (> 0, hasta 2 decimales).'),
        new OA\Property(property: 'activa', type: 'boolean', example: true),
    ],
)]
final class ZonaTarifariaSchema {}
