<?php

declare(strict_types=1);

namespace App\Services\Geolocalizacion;

use RuntimeException;

/**
 * El ping implica un desplazamiento imposible respecto del anterior
 * (velocidad mayor a la máxima configurada).
 */
final class UbicacionInconsistente extends RuntimeException {}
