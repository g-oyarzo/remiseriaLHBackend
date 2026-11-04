<?php

declare(strict_types=1);

namespace App\Services\Clima;

use App\ValueObjects\Coordinate;

/**
 * Consulta el clima actual en un punto para el recargo dinámico.
 */
interface ProveedorClima
{
    /**
     * Precipitación actual en milímetros (último intervalo informado por el
     * proveedor), o null si no se pudo determinar. Nunca lanza excepciones:
     * sin dato de clima simplemente no se aplica el recargo por clima.
     */
    public function precipitacionMm(Coordinate $punto): ?float;
}
