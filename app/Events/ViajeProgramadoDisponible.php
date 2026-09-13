<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Corrección de auditoría (HALL-006): se emite cuando un viaje "programado"
 * entra en la ventana de despacho (ver
 * config('remiseria.ventana_despacho_programados_minutos')) y todavía no
 * tiene conductor asignado. Se difunde en el canal privado de cada
 * conductor disponible (el mismo canal "conductor.{id}" que ya se usa para
 * las actualizaciones de GPS) para que la app del conductor pueda mostrar
 * el viaje como disponible para aceptar.
 *
 * Es un evento en cola (ShouldBroadcast) despachado desde
 * App\Console\Commands\DespacharViajesProgramadosCommand, que corre cada
 * minuto vía el scheduler (routes/console.php). Requiere un worker de colas
 * corriendo (ver HALL-019).
 */
class ViajeProgramadoDisponible implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $conductorIdsDisponibles  Ids de Persona de los
     *   conductores actualmente en servicio y sin viaje activo, a quienes se
     *   les notifica este viaje.
     */
    public function __construct(
        public readonly int $viajeId,
        public readonly string $fechaViaje,
        public readonly array $conductorIdsDisponibles,
    ) {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $conductorId): Channel => new PrivateChannel("conductor.{$conductorId}"),
            $this->conductorIdsDisponibles,
        );
    }

    public function broadcastAs(): string
    {
        return 'viaje.programado.disponible';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'viaje_id' => $this->viajeId,
            'fecha_viaje' => $this->fechaViaje,
        ];
    }
}
