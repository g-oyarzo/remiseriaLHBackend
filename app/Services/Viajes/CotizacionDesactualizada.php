<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Services\Cotizacion\Cotizacion;
use RuntimeException;

/**
 * El monto que el cliente confirmó ya no coincide con la cotización vigente
 * (por ejemplo, cambió la tarifa entre la cotización y la confirmación). El
 * viaje no se registra: el cliente debe aceptar el monto nuevo.
 */
final class CotizacionDesactualizada extends RuntimeException
{
    public function __construct(public readonly Cotizacion $cotizacionVigente)
    {
        parent::__construct('La cotización cambió. Confirme el nuevo monto para continuar.');
    }
}
