<?php

declare(strict_types=1);

namespace App\Services\Cotizacion;

use App\Models\CotizacionEmitida;
use App\ValueObjects\Coordinate;

/**
 * Precio cerrado: emite cotizaciones con vigencia y las consume al
 * registrar el viaje.
 *
 * Al usar una cotización vigente el viaje toma exactamente su monto y su
 * snapshot de tarifa, sin volver a cotizar: un cambio del recargo dinámico
 * o de la tarifa entre la cotización y la solicitud no altera el precio que
 * vio el pasajero.
 */
final class CotizacionesEmitidas
{
    /** Diferencia máxima admitida entre los puntos cotizados y los solicitados. */
    private const TOLERANCIA_METROS = 15.0;

    public function emitir(int $cuentaId, Coordinate $origen, Coordinate $destino, bool $esReserva, Cotizacion $cotizacion): CotizacionEmitida
    {
        $atributos = $cotizacion->atributosViaje();
        $atributos['metodo_cotizacion'] = $cotizacion->metodo->value;

        return CotizacionEmitida::query()->create([
            'cuenta_id' => $cuentaId,
            'origen_lat' => $origen->lat,
            'origen_lng' => $origen->lng,
            'destino_lat' => $destino->lat,
            'destino_lng' => $destino->lng,
            'es_reserva' => $esReserva,
            'monto' => $cotizacion->monto,
            'atributos' => $atributos,
            'respuesta' => $cotizacion->toArray(),
            'expira_en' => now()->addMinutes((int) config('remiseria.cotizacion.vigencia_minutos')),
        ]);
    }

    /**
     * Marca la cotización como usada por el viaje y devuelve los atributos
     * a copiar. Debe llamarse dentro de la transacción que crea el viaje:
     * bloquea la fila para que dos solicitudes no usen la misma cotización.
     *
     * @return array<string, mixed>
     *
     * @throws CotizacionNoUtilizable
     */
    public function consumir(string $id, int $cuentaId, Coordinate $origen, Coordinate $destino, bool $esReserva): array
    {
        $cotizacion = CotizacionEmitida::query()->lockForUpdate()->find($id);

        // Una cotización de otra cuenta se informa igual que una inexistente.
        if ($cotizacion === null || $cotizacion->cuenta_id !== $cuentaId) {
            throw CotizacionNoUtilizable::invalida('La cotización indicada no existe.');
        }

        if ($cotizacion->usada_en !== null || $cotizacion->expira_en->isPast()) {
            throw CotizacionNoUtilizable::vencida();
        }

        if ($cotizacion->es_reserva !== $esReserva) {
            throw CotizacionNoUtilizable::invalida($esReserva
                ? 'La cotización no es para una reserva.'
                : 'La cotización es para una reserva.');
        }

        $mismoOrigen = $origen->distanciaEnMetrosHacia(new Coordinate($cotizacion->origen_lat, $cotizacion->origen_lng)) <= self::TOLERANCIA_METROS;
        $mismoDestino = $destino->distanciaEnMetrosHacia(new Coordinate($cotizacion->destino_lat, $cotizacion->destino_lng)) <= self::TOLERANCIA_METROS;

        if (! $mismoOrigen || ! $mismoDestino) {
            throw CotizacionNoUtilizable::invalida('El origen o el destino no coinciden con los de la cotización.');
        }

        $cotizacion->update(['usada_en' => now()]);

        return $cotizacion->atributos;
    }

    public function vincularViaje(string $id, int $viajeId): void
    {
        CotizacionEmitida::query()->whereKey($id)->update(['viaje_id' => $viajeId]);
    }
}
