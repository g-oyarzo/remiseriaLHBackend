<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado operativo del vehículo, según el DER: {operando, fueraDeServicio, enReparacion}.
 */
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
