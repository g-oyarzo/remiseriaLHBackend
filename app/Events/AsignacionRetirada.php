<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Contracts\DifusionEnTiempoReal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La oferta pendiente de un conductor dejó de estar disponible (venció o el
 * cliente canceló el viaje), para que su app la quite de la pantalla.
 */
class AsignacionRetirada implements DifusionEnTiempoReal
{
    use Dispatchable, InteractsWithSockets;

    public int $tries = 3;

    public function __construct(
        public readonly int $asignacionId,
        public readonly int $viajeId,
        public readonly int $conductorId,
        public readonly string $motivo,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conductor.{$this->conductorId}")];
    }

    public function broadcastAs(): string
    {
        return 'asignacion.retirada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'asignacion_id' => $this->asignacionId,
            'viaje_id' => $this->viajeId,
            'motivo' => $this->motivo,
        ];
    }
}
