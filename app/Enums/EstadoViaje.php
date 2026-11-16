<?php

declare(strict_types=1);

namespace App\Enums;

use OpenApi\Attributes as OA;

/**
 * Estados posibles de un viaje, según el DER: {solicitado, aceptado, en curso,
 * finalizado, cancelado}, más "reservado" (CU 09): una reserva a futuro que
 * todavía no entró en la ventana de despacho y por lo tanto no se le ofrece
 * a ningún conductor.
 */
#[OA\Schema(
    schema: 'EstadoViajeEnum',
    type: 'string',
    enum: ['reservado', 'solicitado', 'aceptado', 'en_curso', 'finalizado', 'cancelado'],
    example: 'solicitado',
)]
enum EstadoViaje: string
{
    case Reservado = 'reservado';
    case Solicitado = 'solicitado';
    case Aceptado = 'aceptado';
    case EnCurso = 'en_curso';
    case Finalizado = 'finalizado';
    case Cancelado = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Reservado => 'Reservado',
            self::Solicitado => 'Solicitado',
            self::Aceptado => 'Aceptado',
            self::EnCurso => 'En curso',
            self::Finalizado => 'Finalizado',
            self::Cancelado => 'Cancelado',
        };
    }

    /**
     * Indica si el viaje llegó a un estado terminal (no puede volver a mutar).
     */
    public function esFinal(): bool
    {
        return in_array($this, [self::Finalizado, self::Cancelado], true);
    }
}
