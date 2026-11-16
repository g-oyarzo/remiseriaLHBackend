<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use App\ValueObjects\Coordinate;

/**
 * Driver "ninguno": no hay servicio de mapas configurado, por lo que el
 * cotizador siempre recurre a la contingencia por zonas.
 */
final class SinProveedorDistancia implements ProveedorDistancia
{
    public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
    {
        throw new ServicioMapasNoDisponible('No hay un servicio de mapas configurado (MAPAS_DRIVER=ninguno).');
    }
}
