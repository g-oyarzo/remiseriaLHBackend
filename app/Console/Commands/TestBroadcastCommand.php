<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\TestBroadcastingEvent;
use Illuminate\Console\Command;

/**
 * Comando de verificación manual para la infraestructura de WebSockets
 * (Tarea 1, criterio de aceptación #3).
 *
 * Uso:
 *   1. `php artisan reverb:start` (en una terminal)
 *   2. Conectar un cliente WS al canal público "test-channel", p.ej. con
 *      wscat: `wscat -c "ws://127.0.0.1:8080/app/{REVERB_APP_KEY}"`
 *   3. `php artisan broadcast:test` (en otra terminal)
 *   4. El cliente debería recibir un evento "test.event" con el mensaje.
 */
class TestBroadcastCommand extends Command
{
    protected $signature = 'broadcast:test {mensaje? : Mensaje opcional a emitir}';

    protected $description = 'Emite App\Events\TestBroadcastingEvent para verificar la conexión de WebSockets (Reverb/Redis).';

    public function handle(): int
    {
        $mensaje = $this->argument('mensaje') ?? 'Conexión de WebSockets operativa.';

        $evento = new TestBroadcastingEvent(mensaje: $mensaje);

        event($evento);

        $this->info('Evento "test.event" emitido en el canal "test-channel".');
        $this->line("Mensaje: {$evento->mensaje}");
        $this->line("Emitido en: {$evento->emitidoEn}");

        return self::SUCCESS;
    }
}
