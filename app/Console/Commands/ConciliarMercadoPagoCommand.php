<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EstadoPago;
use App\Models\Pago;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\MercadoPagoNoDisponible;
use Illuminate\Console\Command;

/**
 * Red de seguridad de los webhooks: busca en Mercado Pago las operaciones de
 * los cobros todavía pendientes (por external_reference) y las aplica con
 * las mismas validaciones que el webhook. Idempotente.
 */
class ConciliarMercadoPagoCommand extends Command
{
    protected $signature = 'pagos:conciliar-mercadopago
        {--dias=3 : Antigüedad máxima (por última actualización) de los cobros pendientes a revisar}';

    protected $description = 'Concilia contra la API de Mercado Pago los cobros digitales pendientes';

    public function handle(CobrosMercadoPago $cobros): int
    {
        if (! CobrosMercadoPago::habilitado()) {
            $this->info('Mercado Pago no está configurado; no hay nada que conciliar.');

            return self::SUCCESS;
        }

        $dias = max(1, (int) $this->option('dias'));
        $revisados = 0;
        $confirmados = 0;
        $fallidos = 0;

        Pago::query()
            ->where('proveedor', CobrosMercadoPago::PROVEEDOR)
            ->where('estado', EstadoPago::Pendiente)
            ->whereNotNull('preferencia_id')
            ->where('updated_at', '>=', now()->subDays($dias))
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (Pago $pago) use ($cobros, &$revisados, &$confirmados, &$fallidos): void {
                $revisados++;

                try {
                    $resultados = $cobros->conciliar($pago);
                } catch (MercadoPagoNoDisponible) {
                    $fallidos++;

                    return;
                }

                if (in_array('confirmado', $resultados, true)) {
                    $confirmados++;
                }
            });

        $this->info("Cobros revisados: {$revisados}. Confirmados: {$confirmados}. Sin respuesta de Mercado Pago: {$fallidos}.");

        return $fallidos > 0 && $fallidos === $revisados ? self::FAILURE : self::SUCCESS;
    }
}
