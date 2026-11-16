<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Enums\EstadoViaje;
use App\Events\ViajeListoParaDespacho;
use App\Models\Viaje;
use Illuminate\Support\Facades\DB;

/**
 * Pasa a despacho (reservado -> solicitado) las reservas que entraron en la
 * ventana configurada antes de su fecha_viaje.
 *
 * Lo invocan tanto el job demorado que se programa al crear la reserva como
 * el comando del scheduler (red de seguridad si un job se pierde). La
 * transición se hace con la fila bloqueada y re-chequeando el estado, por
 * lo que es idempotente: ejecutarla dos veces sobre la misma reserva no la
 * despacha dos veces.
 */
final class DespachoDeReservas
{
    public static function limite(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('remiseria.ventana_despacho_programados_minutos'));
    }

    /**
     * @return bool true si la reserva pasó a despacho en esta llamada.
     */
    public function despachar(int $viajeId): bool
    {
        return DB::transaction(function () use ($viajeId): bool {
            $viaje = Viaje::query()->lockForUpdate()->find($viajeId);

            if ($viaje === null
                || $viaje->estado !== EstadoViaje::Reservado
                || $viaje->fecha_viaje->greaterThan(self::limite())) {
                return false;
            }

            $viaje->update([
                'estado' => EstadoViaje::Solicitado,
                'despachado_en' => now(),
            ]);

            ViajeListoParaDespacho::dispatch($viaje->id);

            return true;
        });
    }

    /**
     * Despacha todas las reservas dentro de la ventana.
     *
     * @return int cantidad de reservas despachadas.
     */
    public function despacharPendientes(): int
    {
        $despachadas = 0;

        Viaje::query()
            ->where('estado', EstadoViaje::Reservado)
            ->where('fecha_viaje', '<=', self::limite())
            ->orderBy('fecha_viaje')
            ->pluck('id')
            ->each(function (int $id) use (&$despachadas): void {
                $despachadas += (int) $this->despachar($id);
            });

        return $despachadas;
    }
}
