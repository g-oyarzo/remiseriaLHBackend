<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use App\Enums\EstadoConductor;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CambiarEstadoConductorRequest',
    type: 'object',
    description: 'Baja (o reactivación) lógica de un conductor por un administrador. No borra el legajo: lo marca "eliminado" (o lo reactiva a "activo") y, al dar de baja, lo saca automáticamente de servicio.',
    required: ['estado'],
    properties: [
        new OA\Property(property: 'estado', ref: EstadoConductor::class),
    ],
)]
final class CambiarEstadoConductorRequest
{
}
