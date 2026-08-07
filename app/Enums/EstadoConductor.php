<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado del legajo del conductor. `Eliminado` representa la baja lógica
 * descrita en CU 13 (el registro se conserva por motivos históricos, pero
 * deja de recibir despachos).
 */
enum EstadoConductor: string
{
    case Activo = 'activo';
    case Eliminado = 'eliminado';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Eliminado => 'Eliminado',
        };
    }
}
