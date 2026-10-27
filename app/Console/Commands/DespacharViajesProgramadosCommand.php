<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Viajes\DespachoDeReservas;
use Illuminate\Console\Command;

/**
 * Pasa a despacho las reservas (CU 09) que entraron en la ventana
 * configurada antes de su fecha_viaje (reservado -> solicitado) y dispara
 * la asignación automática de conductor.
 *
 * Cada reserva ya tiene un job demorado (DespacharReservaJob) programado al
 * crearse; este comando, que corre cada minuto desde el scheduler
 * (routes/console.php), es la red de seguridad para las reservas cuyo job se
 * haya perdido o demorado. La transición es idempotente (fila bloqueada y
 * estado re-chequeado), así que ambos mecanismos pueden coincidir sin
 * despachar dos veces la misma reserva.
 */
class DespacharViajesProgramadosCommand extends Command
{
    protected $signature = 'viajes:despachar-programados';

    protected $description = 'Pasa a despacho las reservas que entraron en la ventana previa a su fecha_viaje.';

    public function handle(DespachoDeReservas $despacho): int
    {
        $despachadas = $despacho->despacharPendientes();

        $this->info("Reservas pasadas a despacho: {$despachadas}");

        return self::SUCCESS;
    }
}
