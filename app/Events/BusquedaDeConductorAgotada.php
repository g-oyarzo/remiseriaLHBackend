<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Contracts\DifusionEnTiempoReal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * No se encontró conductor para un viaje tras agotar ofertas o reintentos.
 * El viaje sigue "solicitado"; se avisa al panel de despacho para que un
 * administrador decida (reintentar con POST /admin/viajes/{id}/reasignar,
 * contactar al cliente o cancelar).
 */
class BusquedaDeConductorAgotada implements DifusionEnTiempoReal
{
    use Dispatchable, InteractsWithSockets;

    public int $tries = 3;

    public function __construct(
        public readonly int $viajeId,
        public readonly string $motivo,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.despacho')];
    }

    public function broadcastAs(): string
    {
        return 'busqueda.agotada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'viaje_id' => $this->viajeId,
            'motivo' => $this->motivo,
        ];
    }
}
