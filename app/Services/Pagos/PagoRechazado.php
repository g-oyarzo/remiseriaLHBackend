<?php

declare(strict_types=1);

namespace App\Services\Pagos;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * El cobro no puede registrarse o conciliarse: estado inválido o pago
 * existente (409), o monto que no coincide con el importe del viaje (422).
 */
final class PagoRechazado extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function __construct(string $mensaje, public readonly int $status = Response::HTTP_CONFLICT, public readonly array $datos = [])
    {
        parent::__construct($mensaje);
    }
}
