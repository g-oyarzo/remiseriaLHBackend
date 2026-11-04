<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\EstadoPago;
use App\Events\Contracts\DifusionEnTiempoReal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se difunde en el canal privado del viaje cada vez que su pago se crea o
 * cambia de estado (por ejemplo, cuando Mercado Pago confirma el cobro), para
 * que las apps del pasajero y del conductor se actualicen sin consultar. El
 * payload es mínimo: el detalle se obtiene por REST.
 */
class PagoActualizado implements DifusionEnTiempoReal
{
    use Dispatchable, InteractsWithSockets;

    public int $tries = 3;

    public function __construct(
        public readonly int $viajeId,
        public readonly int $pagoId,
        public readonly EstadoPago $estado,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("viaje.{$this->viajeId}")];
    }

    public function broadcastAs(): string
    {
        return 'pago.actualizado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'viaje_id' => $this->viajeId,
            'pago_id' => $this->pagoId,
            'estado' => $this->estado->value,
        ];
    }
}
