<?php

declare(strict_types=1);

namespace App\Services\Asignacion;

/**
 * Conductor candidato a recibir la oferta de un viaje, con su puntaje
 * (menor es mejor).
 */
final readonly class Candidato
{
    public function __construct(
        public int $conductorId,
        public int $distanciaMetros,
        public float $puntaje,
    ) {}
}
