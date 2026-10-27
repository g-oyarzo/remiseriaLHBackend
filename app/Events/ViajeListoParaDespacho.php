<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un viaje quedó en estado "solicitado" y debe comenzar la búsqueda de
 * conductor (CU 03): una solicitud inmediata recién registrada o una reserva
 * que entró en la ventana de despacho.
 *
 * Se emite recién después del commit de la transacción que creó o despachó
 * el viaje, para que el job de asignación nunca lea un viaje que todavía no
 * existe (o que terminó revirtiéndose).
 */
class ViajeListoParaDespacho implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $viajeId) {}
}
