<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use RuntimeException;

/**
 * El viaje no puede finalizarse: estado inválido (409), ubicación
 * desactualizada (409) o el conductor está fuera del radio del destino (422).
 */
final class FinalizacionNoPermitida extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function __construct(string $mensaje, public readonly int $status, public readonly array $datos = [])
    {
        parent::__construct($mensaje);
    }
}
