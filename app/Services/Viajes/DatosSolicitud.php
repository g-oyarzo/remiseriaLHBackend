<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Enums\MetodoPago;
use App\ValueObjects\Coordinate;
use Carbon\CarbonInterface;

/**
 * Datos validados de una solicitud o reserva de viaje, independientes del
 * canal (app del cliente o carga telefónica del administrador).
 */
final readonly class DatosSolicitud
{
    /**
     * @param  array{localidad?: ?string, calle?: ?string, numero?: ?string}  $direccionOrigen
     * @param  array{localidad?: ?string, calle?: ?string, numero?: ?string}  $direccionDestino
     */
    public function __construct(
        public int $clienteId,
        public Coordinate $origen,
        public Coordinate $destino,
        public MetodoPago $metodoPago,
        public array $direccionOrigen = [],
        public array $direccionDestino = [],
        public ?CarbonInterface $fechaReserva = null,
        public ?string $montoConfirmado = null,
        public string $canal = 'app',
        public ?int $registradoPorPersonaId = null,
        // Precio cerrado: cotización emitida a usar y cuenta que la pidió.
        public ?string $cotizacionId = null,
        public ?int $cuentaId = null,
    ) {}
}
