<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de viaje según el DER: {actual, programado}.
 */
enum TipoViaje: string
{
    case Actual = 'actual';
    case Programado = 'programado';

    public function label(): string
    {
        return match ($this) {
            self::Actual => 'Inmediato',
            self::Programado => 'Programado',
        };
    }
}
