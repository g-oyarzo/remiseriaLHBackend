<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use RuntimeException;

/**
 * El proveedor de mapas no pudo devolver una ruta válida (error de red,
 * timeout, respuesta inválida o sin ruta). El mensaje nunca incluye la URL
 * ni las credenciales del proveedor.
 */
final class ServicioMapasNoDisponible extends RuntimeException {}
