<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Estado operativo del vehículo, según el DER: {operando, fueraDeServicio, enReparacion}.
 */
#[OA\Schema(
    schema: 'EstadoVehiculoEnum',
    type: 'string',
    enum: ['operando', 'fueraDeServicio', 'enReparacion'],
    example: 'operando',
)]
enum EstadoVehiculo: string
{
    case Operando = 'operando';
    case FueraDeServicio = 'fueraDeServicio';
    case EnReparacion = 'enReparacion';

    public function label(): string
    {
        return match ($this) {
            self::Operando => 'Operando',
            self::FueraDeServicio => 'Fuera de servicio',
            self::EnReparacion => 'En reparación',
        };
    }
}