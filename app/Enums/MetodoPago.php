<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Método de pago, según el DER: {efectivo, tarjeta debito, tarjeta credito, qr}.
 */
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
