<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\EstadoViaje;
use App\Events\ViajeCambioEstado;
use App\Jobs\CobrarViajeAutomaticamenteJob;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;

/**
 * Al finalizar un viaje, encola el cobro automático (solo si la función
 * está habilitada; el job verifica el resto de las condiciones).
 */
class IniciarCobroAutomatico
{
    public function handle(ViajeCambioEstado $evento): void
    {
        if ($evento->estadoNuevo === EstadoViaje::Finalizado && CobrosMercadoPago::pagosAutomaticosHabilitados()) {
            CobrarViajeAutomaticamenteJob::dispatch($evento->viajeId);
        }
    }
}
