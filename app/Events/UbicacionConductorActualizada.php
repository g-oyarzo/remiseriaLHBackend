<?php

declare(strict_types=1);

namespace App\Events;

use App\ValueObjects\Coordinate;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite cada vez que un conductor actualiza su posición GPS
 * (UbicacionController::update). Es un evento en cola (ShouldBroadcast, no
 * ShouldBroadcastNow) para no bloquear la respuesta HTTP del PATCH; requiere
 * un worker corriendo (`php artisan queue:work` / `queue:listen`).
 *
 * Se difunde en el canal privado del conductor (para el propio conductor y
 * un eventual panel de despacho/admin) y, si el conductor tiene un viaje
 * activo asignado, también en el canal de ese viaje para que el cliente
 * pueda seguirlo en el mapa en tiempo real.
 */
class UbicacionConductorActualizada implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conductorId,
        public readonly Coordinate $ubicacion,
        public readonly ?int $viajeActivoId = null,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $canales = [
            new PrivateChannel("conductor.{$this->conductorId}"),
        ];

        if ($this->viajeActivoId !== null) {
            $canales[] = new PrivateChannel("viaje.{$this->viajeActivoId}");
        }

        return $canales;
    }

    public function broadcastAs(): string
    {
        return 'ubicacion.actualizada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conductor_id' => $this->conductorId,
            'ubicacion' => $this->ubicacion->toArray(),
            'viaje_id' => $this->viajeActivoId,
        ];
    }
}