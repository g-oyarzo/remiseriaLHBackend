<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PagoConfirmado;
use App\Services\Comprobantes\EmisionDeComprobantes;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Emite el comprobante del viaje en segundo plano al confirmarse el pago.
 * Si la cola se demora, la descarga lo emite igual (emitir() es
 * idempotente).
 */
class EmitirComprobante implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly EmisionDeComprobantes $emision) {}

    public function handle(PagoConfirmado $evento): void
    {
        $this->emision->emitir($evento->pagoId);
    }
}
