<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Tipo de viaje según el DER: {actual, programado}.
 */
#[OA\Schema(
    schema: 'TipoViajeEnum',
    type: 'string',
    enum: ['actual', 'programado'],
    example: 'actual',
)]
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