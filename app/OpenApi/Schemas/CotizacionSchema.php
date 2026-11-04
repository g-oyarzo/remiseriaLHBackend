<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\MetodoCotizacion;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Cotizacion',
    type: 'object',
    properties: [
        new OA\Property(property: 'monto', type: 'string', example: '4317.50', description: 'Precio cerrado a cobrar, en pesos, con dos decimales (incluye el recargo dinámico).'),
        new OA\Property(property: 'monto_base', type: 'string', example: '3925.00', description: 'Monto antes del recargo dinámico.'),
        new OA\Property(property: 'recargo', type: 'object', description: 'Recargo dinámico aplicado.', properties: [
            new OA\Property(property: 'porcentaje', type: 'string', example: '10.00'),
            new OA\Property(property: 'demanda_porcentaje', type: 'string', example: '10.00'),
            new OA\Property(property: 'clima_porcentaje', type: 'string', example: '0.00'),
            new OA\Property(property: 'ratio_demanda', type: 'number', format: 'float', nullable: true, example: 2.5),
            new OA\Property(property: 'precipitacion_mm', type: 'number', format: 'float', nullable: true, example: null),
            new OA\Property(property: 'motivos', type: 'array', items: new OA\Items(type: 'string'), example: ['Alta demanda en la zona.']),
        ]),
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
