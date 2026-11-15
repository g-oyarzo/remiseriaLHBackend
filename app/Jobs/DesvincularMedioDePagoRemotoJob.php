<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MedioPagoGuardado;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\MediosDePagoGuardados;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Borra en Mercado Pago la tarjeta (o cancela el perfil de pago) que el
 * pasajero desvinculó. En el sistema la baja ya rige desde el pedido.
 */
class DesvincularMedioDePagoRemotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $medioId)
    {
        $this->afterCommit();
    }

    public function handle(MediosDePagoGuardados $medios): void
    {
        $medio = MedioPagoGuardado::query()->find($this->medioId);

        if ($medio === null || $medio->desvinculado_en === null || ! CobrosMercadoPago::habilitado()) {
            return;
        }

        try {
            $medios->desvincularEnProveedor($medio);
        } catch (MercadoPagoNoDisponible $e) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 3600);

                return;
            }

            Log::error('No se pudo borrar en Mercado Pago una tarjeta desvinculada; borrarla a mano.', [
                'medio_pago_id' => $this->medioId, 'error' => $e->getMessage(),
            ]);
        }
    }
}
