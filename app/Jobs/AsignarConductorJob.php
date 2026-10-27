<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Viaje;
use App\Services\Asignacion\AsignadorConductores;
use App\Services\Asignacion\ResultadoBusqueda;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Job de asignación automática de conductor (CU 03).
 *
 * Si no hay candidatos se vuelve a encolar con una demora, hasta
 * max_reintentos_sin_candidatos veces; después la búsqueda se marca como
 * agotada y se avisa al administrador. Ejecutar el job varias veces para el
 * mismo viaje es seguro: el servicio bloquea el viaje y no crea una segunda
 * oferta si ya hay una vigente.
 */
class AsignarConductorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public function __construct(
        public readonly int $viajeId,
        public readonly int $reintentoSinCandidatos = 0,
    ) {
        $this->afterCommit();
    }

    public function handle(AsignadorConductores $asignador): void
    {
        if ($asignador->buscarYOfrecer($this->viajeId) !== ResultadoBusqueda::SinCandidatos) {
            return;
        }

        if ($this->reintentoSinCandidatos < (int) config('remiseria.asignacion.max_reintentos_sin_candidatos')) {
            self::dispatch($this->viajeId, $this->reintentoSinCandidatos + 1)
                ->delay(now()->addSeconds((int) config('remiseria.asignacion.reintento_sin_candidatos_segundos')));

            return;
        }

        $viaje = Viaje::query()->find($this->viajeId);

        if ($viaje !== null && $viaje->busqueda_agotada_en === null) {
            $asignador->marcarAgotada($viaje, 'No hubo conductores disponibles cerca del origen.');
        }
    }
}
