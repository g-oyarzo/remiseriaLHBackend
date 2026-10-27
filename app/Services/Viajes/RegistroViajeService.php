<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Events\ViajeListoParaDespacho;
use App\Jobs\DespacharReservaJob;
use App\Models\Viaje;
use App\Services\Cotizacion\CotizacionNoDisponible;
use App\Services\Cotizacion\CotizadorService;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;

/**
 * Registro de solicitudes inmediatas (CU 01), reservas (CU 09) y viajes
 * cargados por teléfono (CU 10).
 *
 * La cotización se calcula siempre en el servidor (nunca se confía en un
 * monto enviado por el cliente) y fuera de la transacción, para no mantener
 * una transacción abierta mientras se espera al servicio de mapas. El viaje
 * guarda el snapshot de la cotización.
 */
final class RegistroViajeService
{
    public function __construct(private readonly CotizadorService $cotizador) {}

    /**
     * @throws CotizacionNoDisponible
     * @throws CotizacionDesactualizada
     */
    public function registrar(DatosSolicitud $datos): Viaje
    {
        $cotizacion = $this->cotizador->cotizar($datos->origen, $datos->destino);

        // Si el cliente envió el monto que aceptó, debe coincidir al centavo
        // con la cotización vigente; si no, se le devuelve la nueva.
        if ($datos->montoConfirmado !== null
            && Dinero::aCentavos($datos->montoConfirmado) !== Dinero::aCentavos($cotizacion->monto)) {
            throw new CotizacionDesactualizada($cotizacion);
        }

        $esReserva = $datos->fechaReserva !== null;

        $viaje = DB::transaction(function () use ($datos, $cotizacion, $esReserva): Viaje {
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
            ] + $cotizacion->atributosViaje());

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
