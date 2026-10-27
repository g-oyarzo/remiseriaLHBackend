<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Viajes\DespachoDeReservas;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recordatorio programado al crear una reserva (CU 09): se encola con una
 * demora hasta el inicio de la ventana de despacho. Si se ejecuta antes de
 * tiempo (o la reserva fue cancelada) no hace nada; el comando
 * viajes:despachar-programados cubre las reservas cuyo job se haya perdido.
 */
class DespacharReservaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $viajeId)
    {
        $this->afterCommit();
    }

    public function handle(DespachoDeReservas $despacho): void
    {
        $despacho->despachar($this->viajeId);
    }
}
