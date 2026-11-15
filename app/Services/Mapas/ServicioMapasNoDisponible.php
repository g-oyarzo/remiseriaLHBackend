<?php

declare(strict_types=1);

namespace App\Services\Mapas;

use RuntimeException;

/**
 * El proveedor de mapas no pudo devolver una ruta válida (error de red,
 * timeout, respuesta inválida, cuota agotada o circuito abierto). El
 * cotizador aplica la contingencia por zonas. El mensaje nunca incluye la
 * URL ni las credenciales del proveedor.
 *
 * Subclases: RutaInexistente (el proveedor respondió bien, pero no hay
 * recorrido por calles) y LimiteDelProveedorAlcanzado (HTTP 429).
 */
class ServicioMapasNoDisponible extends RuntimeException {}
