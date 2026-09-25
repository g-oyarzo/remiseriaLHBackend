<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Estado de la transacción de pago. No está explícito en el DER, pero es
 * necesario para representar las excepciones de CU 07 (fondos insuficientes)
 * sin perder el intento de cobro.
 */
#[OA\Schema(
    schema: 'EstadoPagoEnum',
    type: 'string',
    enum: ['pendiente', 'confirmado', 'rechazado'],
    example: 'confirmado',
)]
enum EstadoPago: string
{
    case Pendiente = 'pendiente';
    case Confirmado = 'confirmado';
    case Rechazado = 'rechazado';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Confirmado => 'Confirmado',
            self::Rechazado => 'Rechazado',
        };
    }
}