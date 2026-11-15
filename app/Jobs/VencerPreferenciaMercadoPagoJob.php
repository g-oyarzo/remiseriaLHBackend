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
 * Vence en Mercado Pago un enlace de pago (preferencia) que quedó
 * reemplazado por otro medio de cobro. Se hace en segundo plano para que una
 * caída de Mercado Pago no impida registrar el cobro en efectivo; si no se
 * logra, el enlace igual vence solo (MERCADOPAGO_PREFERENCIA_VIGENCIA_MINUTOS)
 * y un pago tardío se detecta y se reintegra (CobrosMercadoPago::aplicar).
 */
class VencerPreferenciaMercadoPagoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 60, 120, 300];

    public function __construct(public readonly string $preferenciaId)
    {
        $this->afterCommit();
    }

    public function handle(CobrosMercadoPago $cobros): void
    {
        if (! CobrosMercadoPago::habilitado()) {
            return;
        }

        try {
            $cobros->vencerPreferencia($this->preferenciaId);
        } catch (MercadoPagoNoDisponible $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);

                return;
            }

            Log::error('No se pudo vencer en Mercado Pago un enlace de pago reemplazado; vencerá solo.', [
                'preferencia_id' => $this->preferenciaId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
