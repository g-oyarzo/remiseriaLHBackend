<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Contracts\DifusionEnTiempoReal;
use App\ValueObjects\Coordinate;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se emite con cada ping GPS de un conductor (RegistroDeUbicaciones). Es el
 * "UbicacionActualizadaEvent" de la planificación; se difunde como
 * "ubicacion.actualizada".
 *
 * Canales (todos privados):
 * - conductor.{id}: el propio conductor y el cliente con un viaje activo
 *   con él (ver routes/channels.php).
 * - viaje.{id}: si el conductor tiene un viaje aceptado o en curso, para el
 *   mapa de seguimiento del pasajero (CU 11).
 * - admin.despacho: mapa de la flota del panel administrativo (CU 11).
 *
 * Incluye registrado_en para que los clientes descarten posiciones viejas
 * que lleguen fuera de orden en lugar de tomarlas como actuales.
 *
 * Se encola (no bloquea la respuesta del ping) y es descartable: si Reverb
 * no responde, el siguiente ping vuelve a informar la posición. Requiere un
 * worker de colas corriendo (HALL-019).
 */
class UbicacionConductorActualizada implements DifusionEnTiempoReal
{
    use Dispatchable, InteractsWithSockets;

    /** Un ping perdido se reemplaza con el siguiente: no se reintenta. */
    public int $tries = 1;

    public function __construct(
        public readonly int $conductorId,
        public readonly Coordinate $ubicacion,
        public readonly ?int $viajeActivoId = null,
        public readonly ?string $registradoEn = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $canales = [
            new PrivateChannel("conductor.{$this->conductorId}"),
            new PrivateChannel('admin.despacho'),
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
            'registrado_en' => $this->registradoEn,
        ];
    }
}
