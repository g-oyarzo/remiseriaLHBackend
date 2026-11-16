<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EstadoViaje;
use App\Jobs\AsignarConductorJob;
use App\Models\AsignacionViaje;
use App\Models\Viaje;
use App\Services\Asignacion\AsignadorConductores;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Red de seguridad del despacho, corrida cada minuto por el scheduler:
 *
 * 1. Vence las ofertas cuyo plazo pasó y que su job de expiración no
 *    procesó (job perdido, worker caído).
 * 2. Reencola la búsqueda de viajes "solicitado" sin oferta pendiente y sin
 *    actividad reciente (p. ej. un AsignarConductorJob que se perdió, o
 *    viajes solicitados antes de existir la asignación automática).
 *
 * Ambas acciones son idempotentes respecto de los jobs normales.
 */
class SupervisarDespachoCommand extends Command
{
    protected $signature = 'viajes:supervisar-despacho';

    protected $description = 'Vence ofertas sin respuesta y reactiva búsquedas de conductor detenidas.';

    public function handle(AsignadorConductores $asignador): int
    {
        $vencidas = 0;

        AsignacionViaje::query()
            ->pendientes()
            ->where('expira_en', '<=', now())
            ->orderBy('expira_en')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use ($asignador, &$vencidas): void {
                $vencidas += (int) $asignador->expirar($id);
            });

        // Sin actividad durante el doble del intervalo de reintento: la
        // cadena de jobs normal debería haber actuado en ese lapso.
        $umbral = now()->subSeconds(2 * max(30, (int) config('remiseria.asignacion.reintento_sin_candidatos_segundos')));

        $reactivados = Viaje::query()
            ->where('estado', EstadoViaje::Solicitado)
            ->whereNull('busqueda_agotada_en')
            ->whereRaw('COALESCE(despachado_en, created_at) <= ?', [$umbral])
            ->whereDoesntHave('asignaciones', fn (Builder $q) => $q->pendientes())
            ->whereDoesntHave('asignaciones', fn (Builder $q) => $q->where('updated_at', '>', $umbral))
            ->limit(200)
            ->pluck('id')
            ->each(fn (int $id) => AsignarConductorJob::dispatch($id))
            ->count();

        $this->info("Ofertas vencidas: {$vencidas}. Búsquedas reactivadas: {$reactivados}.");

        return self::SUCCESS;
    }
}
