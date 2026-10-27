<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Estado de una oferta de viaje a un conductor (CU 03 / CU 05).
 */
#[OA\Schema(
    schema: 'EstadoAsignacionEnum',
    type: 'string',
    enum: ['pendiente', 'aceptada', 'rechazada', 'expirada', 'cancelada'],
    example: 'pendiente',
)]
enum EstadoAsignacion: string
{
    case Pendiente = 'pendiente';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case Expirada = 'expirada';
    case Cancelada = 'cancelada';
}
