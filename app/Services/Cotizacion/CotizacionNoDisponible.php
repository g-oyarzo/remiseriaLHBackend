<?php

declare(strict_types=1);

namespace App\Services\Cotizacion;

use RuntimeException;

/**
 * No es posible cotizar: falta configuración (no hay tarifa vigente) o el
 * trayecto es inválido (origen y destino iguales o distancia fuera de rango).
 */
final class CotizacionNoDisponible extends RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $faltaConfiguracion = false)
    {
        parent::__construct($mensaje);
    }

    public static function sinTarifaVigente(): self
    {
        return new self('No hay una tarifa vigente configurada.', faltaConfiguracion: true);
    }
}
