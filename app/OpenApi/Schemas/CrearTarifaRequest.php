<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CrearTarifaRequest',
    type: 'object',
    description: 'Crea una nueva versión vigente de la tarifa y cierra la anterior (vigente_hasta = now()). Montos > 0 con hasta dos decimales.',
    required: ['precio_base', 'precio_por_km'],
    properties: [
        new OA\Property(property: 'precio_base', type: 'number', format: 'float', minimum: 0, exclusiveMinimum: true, example: 1500.00),
        new OA\Property(property: 'precio_por_km', type: 'number', format: 'float', minimum: 0, exclusiveMinimum: true, example: 350.00),
        new OA\Property(property: 'zona', type: 'string', maxLength: 60, nullable: true, example: 'Casco urbano'),
        new OA\Property(property: 'demanda_umbral_ratio', type: 'number', format: 'float', nullable: true, example: 2.0, description: 'Solicitudes pendientes por conductor disponible a partir de las cuales hay alta demanda. null desactiva el recargo por demanda. Si se omite, se conserva el de la versión vigente.'),
        new OA\Property(property: 'demanda_recargo_pct', type: 'number', format: 'float', minimum: 0, maximum: 300, example: 15),
        new OA\Property(property: 'lluvia_umbral_mm', type: 'number', format: 'float', minimum: 0, example: 0.1),
        new OA\Property(property: 'lluvia_recargo_pct', type: 'number', format: 'float', minimum: 0, maximum: 300, example: 10),
        new OA\Property(property: 'recargo_maximo_pct', type: 'number', format: 'float', minimum: 0, maximum: 300, example: 50, description: 'Tope del recargo combinado.'),
    ],
)]
final class CrearTarifaRequest
{
}