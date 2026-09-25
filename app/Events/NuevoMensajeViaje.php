<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Mensaje;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite cuando se envía un mensaje de chat dentro de un viaje
 * (MensajeController::store). Se difunde en el canal privado del viaje para
 * que tanto el cliente como el conductor reciban el mensaje en tiempo real.
 */
class NuevoMensajeViaje implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Mensaje $mensaje,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("viaje.{$this->mensaje->viaje_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mensaje.nuevo';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->mensaje->id,
            'viaje_id' => $this->mensaje->viaje_id,
            'emisor_persona_id' => $this->mensaje->emisor_persona_id,
            'receptor_persona_id' => $this->mensaje->receptor_persona_id,
            'contenido' => $this->mensaje->contenido,
            'leido' => $this->mensaje->leido,
            'created_at' => $this->mensaje->created_at?->toIso8601String(),
        ];
    }
}
