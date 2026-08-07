<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados posibles de un viaje, según el DER: {solicitado, aceptado, en curso,
 * finalizado, cancelado}.
 */
enum EstadoViaje: string
{
    case Solicitado = 'solicitado';
    case Aceptado = 'aceptado';
    case EnCurso = 'en_curso';
    case Finalizado = 'finalizado';
    case Cancelado = 'cancelado';

    public function label(): string
    {
        return match ($this) {
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
