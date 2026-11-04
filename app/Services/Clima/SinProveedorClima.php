<?php

declare(strict_types=1);

namespace App\Services\Clima;

use App\ValueObjects\Coordinate;

/**
 * CLIMA_DRIVER=ninguno: no se consulta el clima y no hay recargo por lluvia.
 */
final class SinProveedorClima implements ProveedorClima
{
    public function precipitacionMm(Coordinate $punto): ?float
    {
        return null;
    }
}
