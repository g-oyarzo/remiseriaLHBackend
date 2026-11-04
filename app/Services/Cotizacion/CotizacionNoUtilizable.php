<?php

declare(strict_types=1);

namespace App\Services\Cotizacion;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La cotización indicada no se puede usar: no existe o es de otra cuenta
 * (422), no corresponde al trayecto o al tipo de viaje (422), o venció o ya
 * se usó (409, hay que cotizar de nuevo).
 */
final class CotizacionNoUtilizable extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $status)
    {
        parent::__construct($mensaje);
    }

    public static function invalida(string $mensaje): self
    {
        return new self($mensaje, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function vencida(): self
    {
        return new self('La cotización venció o ya fue utilizada. Cotice nuevamente.', Response::HTTP_CONFLICT);
    }
}
