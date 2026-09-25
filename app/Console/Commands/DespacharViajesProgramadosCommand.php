<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Events\ViajeProgramadoDisponible;
use App\Models\Conductor;
use App\Models\Viaje;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corrección de auditoría (HALL-006): los viajes tipo "programado" no tenían
 * ningún mecanismo que los hiciera visibles/asignables a conductores cuando
 * se acercaba su hora, así que quedaban en estado "solicitado"
 * indefinidamente hasta que, por casualidad, algún conductor los viera en el
 * listado general.
 *
 * Este comando corre cada minuto (ver Schedule en routes/console.php) y, por
 * cada viaje programado sin conductor que ya entró en la ventana de despacho
 * (config('remiseria.ventana_despacho_programados_minutos') antes de su
 * fecha_viaje), notifica a los conductores actualmente disponibles vía
 * WebSocket (ViajeProgramadoDisponible) para que puedan aceptarlo con el
 * flujo normal (POST /conductor/viajes/{viaje}/aceptar), que ya maneja la
 * concurrencia correctamente con lockForUpdate.
 *
 * Es intencionalmente NO destructivo: no cambia el estado del viaje ni lo
 * asigna a nadie, solo emite el aviso. Si nadie lo acepta en el próximo
 * minuto, se le vuelve a avisar a los conductores disponibles en ese
 * momento (que pueden ser otros, si cambió quién está en servicio). Este
 * comando es, por lo tanto, idempotente en el sentido de que ejecutarlo
 * varias veces sobre el mismo viaje no asignado no tiene efectos
 * secundarios destructivos ni lo duplica; el `lockForUpdate` protege contra
 * que dos ejecuciones superpuestas del comando lean y notifiquen el mismo
 * viaje a la vez (ver también `withoutOverlapping()` en el scheduler).
 */
class DespacharViajesProgramadosCommand extends Command
{
    protected $signature = 'viajes:despachar-programados';

    protected $description = 'Notifica a conductores disponibles los viajes programados próximos a su fecha_viaje que todavía no tienen conductor asignado.';

    public function handle(): int
    {
        $limite = now()->addMinutes((int) config('remiseria.ventana_despacho_programados_minutos'));

        $viajesNotificados = 0;

        DB::transaction(function () use ($limite, &$viajesNotificados): void {
            $viajes = Viaje::query()
                ->where('estado', EstadoViaje::Solicitado)
                ->where('tipo', TipoViaje::Programado)
                ->whereNull('conductor_id')
                ->where('fecha_viaje', '<=', $limite)
                ->lockForUpdate()
                ->get();

            if ($viajes->isEmpty()) {
                return;
            }

            $conductorIdsDisponibles = Conductor::query()
                ->disponibles()
                ->pluck('persona_id')
                ->all();

            if ($conductorIdsDisponibles === []) {
                Log::info('DespacharViajesProgramadosCommand: hay viajes programados por despachar pero ningún conductor disponible.', [
                    'viaje_ids' => $viajes->pluck('id')->all(),
                ]);

                return;
            }

            foreach ($viajes as $viaje) {
                event(new ViajeProgramadoDisponible(
                    viajeId: $viaje->id,
                    fechaViaje: $viaje->fecha_viaje->toIso8601String(),
                    conductorIdsDisponibles: $conductorIdsDisponibles,
                ));

                $viajesNotificados++;
            }
        });

        $this->info("Viajes programados notificados a conductores disponibles: {$viajesNotificados}");

        return self::SUCCESS;
    }
}
