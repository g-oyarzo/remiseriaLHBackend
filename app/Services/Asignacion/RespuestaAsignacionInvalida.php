<?php

declare(strict_types=1);

namespace App\Services\Asignacion;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un conductor intentó aceptar o rechazar un viaje que no le fue ofrecido
 * (403) o cuya oferta ya no está vigente (409).
 */
final class RespuestaAsignacionInvalida extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $status)
    {
        parent::__construct($mensaje);
    }

    public static function noEsDestinatario(): self
    {
        return new self('Este viaje no le fue asignado.', Response::HTTP_FORBIDDEN);
    }

    public static function conflicto(string $mensaje): self
    {
        return new self($mensaje, Response::HTTP_CONFLICT);
    }
}
