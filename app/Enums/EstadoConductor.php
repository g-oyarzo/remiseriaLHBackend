<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Estado del legajo del conductor. `Eliminado` representa la baja lógica
 * descrita en CU 13 (el registro se conserva por motivos históricos, pero
 * deja de recibir despachos).
 */
#[OA\Schema(
    schema: 'EstadoConductorEnum',
    type: 'string',
    enum: ['activo', 'eliminado'],
    example: 'activo',
)]
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