<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use App\Services\Pagos\PagoRechazado;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reintegro automático de un cobro de Mercado Pago aprobado que no se
 * aplicó (pago duplicado o pagado después de registrar otro medio). Solo se
 * encola con MERCADOPAGO_REINTEGRO_AUTOMATICO=true; si no, lo decide un
 * administrador (POST /admin/pagos/{pago}/reintegrar). Idempotente.
 */
class ReintegrarOperacionMercadoPagoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly int $pagoId,
        public readonly string $mpId,
    ) {}

    public function handle(CobrosMercadoPago $cobros): void
    {
        try {
            $cobros->reintegrarOperacionNoAplicada($this->pagoId, $this->mpId, null);
        } catch (PagoRechazado $e) {
            Log::critical('El reintegro automático de Mercado Pago no corresponde; revisar a mano.', [
                'pago_id' => $this->pagoId, 'mp_id' => $this->mpId, 'motivo' => $e->getMessage(),
            ]);
        } catch (MercadoPagoNoDisponible $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 3600);

                return;
            }

            Log::critical('No se pudo reintegrar automáticamente un cobro de Mercado Pago no aplicado; reintegrar a mano.', [
                'pago_id' => $this->pagoId, 'mp_id' => $this->mpId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
