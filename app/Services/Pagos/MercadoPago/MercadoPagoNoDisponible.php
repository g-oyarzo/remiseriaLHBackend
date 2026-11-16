<?php

declare(strict_types=1);

namespace App\Services\Pagos\MercadoPago;

use RuntimeException;

/**
 * Mercado Pago no está configurado o no respondió correctamente. El mensaje
 * nunca incluye el access token ni el cuerpo de la respuesta.
 */
final class MercadoPagoNoDisponible extends RuntimeException {}
