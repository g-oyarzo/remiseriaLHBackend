<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MetodoPago;
use App\Services\Viajes\DatosSolicitud;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rules\Enum;

/**
 * CU 01 - Solicitar viaje inmediato: origen, destino y método de pago
 * (CU 21). Precio cerrado: con "cotizacion_id" se cobra exactamente el
 * monto cotizado; sin él se cotiza en el momento y, si se envía
 * "monto_confirmado", debe coincidir con esa cotización.
 */
class SolicitarViajeRequest extends CotizarViajeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'metodo_pago' => ['required', new Enum(MetodoPago::class)],
            'origen_localidad' => ['nullable', 'string', 'max:100'],
            'origen_calle' => ['nullable', 'string', 'max:100'],
            'origen_numero' => ['nullable', 'string', 'max:15'],
            'destino_localidad' => ['nullable', 'string', 'max:100'],
            'destino_calle' => ['nullable', 'string', 'max:100'],
            'destino_numero' => ['nullable', 'string', 'max:15'],
            'monto_confirmado' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            // Cotización emitida por POST /viajes/cotizar: si se envía, se
            // cobra exactamente ese monto (precio cerrado).
            'cotizacion_id' => ['nullable', 'uuid', 'prohibits:monto_confirmado'],
        ];
    }

    public function datosSolicitud(int $clienteId, ?CarbonInterface $fechaReserva = null, string $canal = 'app', ?int $registradoPor = null): DatosSolicitud
    {
        $montoConfirmado = $this->validated('monto_confirmado');

        return new DatosSolicitud(
            clienteId: $clienteId,
            origen: $this->origen(),
            destino: $this->destino(),
            metodoPago: MetodoPago::from($this->validated('metodo_pago')),
            direccionOrigen: [
                'localidad' => $this->validated('origen_localidad'),
                'calle' => $this->validated('origen_calle'),
                'numero' => $this->validated('origen_numero'),
            ],
            direccionDestino: [
                'localidad' => $this->validated('destino_localidad'),
                'calle' => $this->validated('destino_calle'),
                'numero' => $this->validated('destino_numero'),
            ],
            fechaReserva: $fechaReserva,
            montoConfirmado: $montoConfirmado !== null ? (string) $montoConfirmado : null,
            canal: $canal,
            registradoPorPersonaId: $registradoPor,
            cotizacionId: $this->validated('cotizacion_id'),
            cuentaId: $this->user()->id,
        );
    }
}
