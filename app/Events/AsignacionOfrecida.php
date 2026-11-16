<?php

declare(strict_types=1);

namespace App\Events;

use App\Events\Contracts\DifusionEnTiempoReal;
use App\Models\AsignacionViaje;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Notifica al conductor elegido que tiene un viaje para aceptar o rechazar
 * antes de expira_en (CU 03, paso 3). Se difunde solo en el canal privado
 * de ese conductor y sin datos personales del cliente: el detalle completo
 * se obtiene por REST después de aceptar.
 */
class AsignacionOfrecida implements DifusionEnTiempoReal
{
    use Dispatchable, InteractsWithSockets;

    public int $tries = 3;

    /** @var array<string, mixed> */
    public readonly array $datos;

    public function __construct(AsignacionViaje $asignacion)
    {
        $viaje = $asignacion->viaje;

        $this->datos = [
            'asignacion_id' => $asignacion->id,
            'viaje_id' => $viaje->id,
            'conductor_id' => $asignacion->conductor_id,
            'expira_en' => $asignacion->expira_en->toIso8601String(),
            'distancia_al_origen_metros' => $asignacion->distancia_metros,
            'origen' => $viaje->origen?->toArray(),
            'destino' => $viaje->destino?->toArray(),
            'origen_direccion' => trim("{$viaje->origen_calle} {$viaje->origen_numero}") ?: null,
            'destino_direccion' => trim("{$viaje->destino_calle} {$viaje->destino_numero}") ?: null,
            'costo_estimado' => $viaje->costo,
            'metodo_pago' => $viaje->metodo_pago?->value,
        ];
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("conductor.{$this->datos['conductor_id']}")];
    }

    public function broadcastAs(): string
    {
        return 'asignacion.ofrecida';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->datos;
    }
}
