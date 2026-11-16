<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cobro automático de un viaje finalizado con el medio autorizado por el
 * pasajero (ver CobrosMercadoPago::cobrarAutomaticamente).
 *
 * Reintentos: la orden se crea con una clave de idempotencia fija por pago,
 * así que reintentar tras una falla de red no genera un segundo cargo. Si
 * Mercado Pago sigue sin responder, el pago queda pendiente y la
 * conciliación periódica consulta la orden.
 */
class CobrarViajeAutomaticamenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(public readonly int $viajeId)
    {
        $this->afterCommit();
    }

    public function handle(CobrosMercadoPago $cobros): void
    {
        try {
            $resultado = $cobros->cobrarAutomaticamente($this->viajeId);
        } catch (MercadoPagoNoDisponible $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);

                return;
            }

            Log::error('No se pudo completar el cobro automático; el viaje queda pendiente de pago.', [
                'viaje_id' => $this->viajeId, 'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($resultado !== 'no_aplica') {
            Log::info('Cobro automático procesado.', ['viaje_id' => $this->viajeId, 'resultado' => $resultado]);
        }
    }
}
