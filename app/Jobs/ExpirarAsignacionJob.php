<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Asignacion\AsignadorConductores;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Vence una oferta que el conductor no respondió a tiempo y dispara la
 * reasignación (CU 03, excepción 4a). Si se ejecuta antes del vencimiento o
 * la oferta ya fue respondida, no hace nada; el comando
 * viajes:supervisar-despacho cubre los jobs que se pierdan.
 */
class ExpirarAsignacionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $asignacionId)
    {
        $this->afterCommit();
    }

    public function handle(AsignadorConductores $asignador): void
    {
        $asignador->expirar($this->asignacionId);
    }
}
