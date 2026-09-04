<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Método de pago, según el DER: {efectivo, tarjeta debito, tarjeta credito, qr}.
 */
#[OA\Schema(
    schema: 'MetodoPagoEnum',
    type: 'string',
    enum: ['efectivo', 'tarjetaDebito', 'tarjetaCredito', 'qr'],
    example: 'efectivo',
)]
enum MetodoPago: string
{
    case Efectivo = 'efectivo';
    case TarjetaDebito = 'tarjetaDebito';
    case TarjetaCredito = 'tarjetaCredito';
    case Qr = 'qr';
    public function label(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::TarjetaDebito => 'Tarjeta de débito',
            self::TarjetaCredito => 'Tarjeta de crédito',
            self::Qr => 'Código QR',
        };
    }
}