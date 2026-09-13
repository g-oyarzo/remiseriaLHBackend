<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de humo para verificar la infraestructura de WebSockets
 * (Reverb + Redis) de punta a punta, sin depender de un worker de colas.
 *
 * Se emite manualmente con `php artisan broadcast:test` (ver
 * App\Console\Commands\TestBroadcastCommand) y se puede escuchar desde un
 * cliente WS de prueba (wscat, Postman) suscrito al canal público
 * "test-channel".
 */
class TestBroadcastingEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public readonly string $emitidoEn;

    public function __construct(
        public readonly string $mensaje = 'Conexión de WebSockets operativa.',
        ?string $emitidoEn = null,
    ) {
        $this->emitidoEn = $emitidoEn ?? now()->toIso8601String();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('test-channel'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'test.event';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'mensaje' => $this->mensaje,
            'emitido_en' => $this->emitidoEn,
        ];
    }
}