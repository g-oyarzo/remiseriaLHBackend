<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un pago pasó a "confirmado" (efectivo al registrarse, conciliación del
 * administrador o confirmación del proveedor de pagos). Se emite después
 * del commit desde Pago::booted().
 */
class PagoConfirmado implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $pagoId) {}
}
