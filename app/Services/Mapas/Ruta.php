<?php

declare(strict_types=1);

namespace App\Services\Mapas;

/**
 * Resultado de una consulta de ruteo: distancia de manejo y tiempo estimado.
 */
final readonly class Ruta
{
    public function __construct(
        public int $distanciaMetros,
        public int $duracionSegundos,
        public string $proveedor,
    ) {}

    public function distanciaKm(): float
    {
        return round($this->distanciaMetros / 1000, 3);
    }

    public function duracionMinutos(): int
    {
        return (int) ceil($this->duracionSegundos / 60);
    }
}
