<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use App\ValueObjects\Coordinate;

/**
 * Calcula la ruta de manejo entre dos puntos con un servicio externo.
 */
interface ProveedorDistancia
{
    /**
     * @throws ServicioMapasNoDisponible
     */
    public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta;
}
