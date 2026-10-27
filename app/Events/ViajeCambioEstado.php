<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\EstadoViaje;
use App\Events\Contracts\DifusionEnTiempoReal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara desde Viaje::booted() cada vez que cambia el atributo
 * `estado`, sin importar desde qué controlador, job o comando se origine.
 *
 * Además de evento de dominio, se difunde en el canal privado del viaje
 * (cliente, conductor asignado y administradores) para que la app del
 * pasajero se entere en tiempo real de que el viaje fue aceptado, iniciado,
 * finalizado o cancelado (CU 05). El payload es mínimo a propósito: la app
 * obtiene el detalle por REST (GET /viajes/{id}).
 *
 * ShouldDispatchAfterCommit: si el cambio de estado ocurre dentro de una
 * transacción que luego se revierte, el evento no se emite.
 */
class ViajeCambioEstado implements DifusionEnTiempoReal
{
    use Dispatchable, InteractsWithSockets;

    public int $tries = 3;

    public function __construct(
        public readonly int $viajeId,
        public readonly EstadoViaje $estadoNuevo,
        public readonly ?int $conductorId = null,
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
        return 'viaje.estado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'viaje_id' => $this->viajeId,
            'estado' => $this->estadoNuevo->value,
            'conductor_id' => $this->conductorId,
        ];
    }
}
