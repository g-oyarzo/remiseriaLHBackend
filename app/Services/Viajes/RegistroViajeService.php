<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Events\ViajeListoParaDespacho;
use App\Jobs\DespacharReservaJob;
use App\Models\Viaje;
use App\Services\Cotizacion\CotizacionesEmitidas;
use App\Services\Cotizacion\CotizacionNoDisponible;
use App\Services\Cotizacion\CotizacionNoUtilizable;
use App\Services\Cotizacion\CotizadorService;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Registro de solicitudes inmediatas (CU 01), reservas (CU 09) y viajes
 * cargados por teléfono (CU 10).
 *
 * La cotización se calcula siempre en el servidor (nunca se confía en un
 * monto enviado por el cliente): o bien se usa una cotización emitida y
 * vigente de la misma cuenta (precio cerrado), o bien se cotiza en el
 * momento, fuera de la transacción para no mantenerla abierta mientras se
 * espera al servicio de mapas. El viaje guarda el snapshot de la cotización.
 */
final class RegistroViajeService
{
    public function __construct(
        private readonly CotizadorService $cotizador,
        private readonly CotizacionesEmitidas $cotizaciones,
    ) {}

    /**
     * @throws CotizacionNoDisponible
     * @throws CotizacionDesactualizada
     * @throws CotizacionNoUtilizable
     */
    public function registrar(DatosSolicitud $datos): Viaje
    {
        $esReserva = $datos->fechaReserva !== null;
        $cotizacion = null;

        // Sin cotización emitida se cotiza en el momento (fuera de la
        // transacción, por la llamada al servicio de mapas).
        if ($datos->cotizacionId === null) {
            $cotizacion = $this->cotizador->cotizar($datos->origen, $datos->destino, esReserva: $esReserva);

            // Si el cliente envió el monto que aceptó, debe coincidir al
            // centavo con la cotización vigente; si no, se le devuelve la nueva.
            if ($datos->montoConfirmado !== null
                && Dinero::aCentavos($datos->montoConfirmado) !== Dinero::aCentavos($cotizacion->monto)) {
                throw new CotizacionDesactualizada($cotizacion);
            }
        }

        $viaje = DB::transaction(function () use ($datos, $cotizacion, $esReserva): Viaje {
            $atributosCotizacion = $cotizacion?->atributosViaje()
                ?? $this->cotizaciones->consumir($datos->cotizacionId, (int) $datos->cuentaId, $datos->origen, $datos->destino, $esReserva);

            $viaje = Viaje::query()->create([
                'cliente_id' => $datos->clienteId,
                'origen' => $datos->origen,
                'destino' => $datos->destino,
                'origen_localidad' => $datos->direccionOrigen['localidad'] ?? null,
                'origen_calle' => $datos->direccionOrigen['calle'] ?? null,
                'origen_numero' => $datos->direccionOrigen['numero'] ?? null,
                'destino_localidad' => $datos->direccionDestino['localidad'] ?? null,
                'destino_calle' => $datos->direccionDestino['calle'] ?? null,
                'destino_numero' => $datos->direccionDestino['numero'] ?? null,
                'estado' => $esReserva ? EstadoViaje::Reservado : EstadoViaje::Solicitado,
                'tipo' => $esReserva ? TipoViaje::Programado : TipoViaje::Actual,
                'metodo_pago' => $datos->metodoPago,
                'canal' => $datos->canal,
                'registrado_por_persona_id' => $datos->registradoPorPersonaId,
                'fecha_viaje' => $datos->fechaReserva ?? now(),
                'despachado_en' => $esReserva ? null : now(),
            ] + $atributosCotizacion);

            if ($datos->cotizacionId !== null) {
                $this->cotizaciones->vincularViaje($datos->cotizacionId, $viaje->id);
            }

            if ($esReserva) {
                // Recordatorio para el inicio de la ventana de despacho; se
                // encola recién al confirmar la transacción (afterCommit).
                DespacharReservaJob::dispatch($viaje->id)->delay(
                    $datos->fechaReserva->copy()->subMinutes((int) config('remiseria.ventana_despacho_programados_minutos')),
                );
            } else {
                ViajeListoParaDespacho::dispatch($viaje->id);
            }

            return $viaje;
        });

        return $viaje->refresh();
    }
}
