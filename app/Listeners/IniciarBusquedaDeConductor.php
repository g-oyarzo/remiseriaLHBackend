<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ViajeListoParaDespacho;
use App\Jobs\AsignarConductorJob;

/**
 * Cuando un viaje entra en despacho se encola la asignación automática.
 */
class IniciarBusquedaDeConductor
{
    public function handle(ViajeListoParaDespacho $evento): void
    {
        AsignarConductorJob::dispatch($evento->viajeId);
    }
}
