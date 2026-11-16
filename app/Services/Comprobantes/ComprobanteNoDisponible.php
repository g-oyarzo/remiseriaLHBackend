<?php

declare(strict_types=1);

namespace App\Services\Comprobantes;

use RuntimeException;

/**
 * No se puede emitir o entregar el comprobante: el pago no está confirmado,
 * o el archivo guardado falta o no coincide con su hash (integridad).
 */
final class ComprobanteNoDisponible extends RuntimeException {}
