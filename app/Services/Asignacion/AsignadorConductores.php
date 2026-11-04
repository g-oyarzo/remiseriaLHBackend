<?php

declare(strict_types=1);

namespace App\Services\Asignacion;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoConductor;
use App\Enums\EstadoViaje;
use App\Events\AsignacionOfrecida;
use App\Events\AsignacionRetirada;
use App\Events\BusquedaDeConductorAgotada;
use App\Jobs\AsignarConductorJob;
use App\Jobs\ExpirarAsignacionJob;
use App\Models\AsignacionViaje;
use App\Models\Conductor;
use App\Models\Viaje;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Algoritmo de asignación automática de conductores (CU 03) y respuesta del
 * conductor a la oferta (CU 05).
 *
 * Concurrencia: toda transición bloquea las filas en el mismo orden (viaje,
 * luego oferta, luego conductor) para evitar deadlocks, y re-chequea el
 * estado ya bloqueado. Las restricciones unique de asignaciones_viaje son la
 * última barrera: una sola oferta pendiente por viaje y por conductor, y
 * nunca dos ofertas del mismo viaje al mismo conductor.
 *
 * Los eventos y jobs que se disparan dentro de las transacciones se encolan
 * recién al confirmarlas (ShouldDispatchAfterCommit / afterCommit()).
 */
final class AsignadorConductores
{
    /**
     * Busca el mejor candidato y le ofrece el viaje.
     *
     * $reintento es la cantidad de búsquedas previas sin candidatos: con
     * cada una el radio de búsqueda se amplía (ver radioDeBusqueda()).
     */
    public function buscarYOfrecer(int $viajeId, int $reintento = 0): ResultadoBusqueda
    {
        $viaje = Viaje::query()->find($viajeId);

        if (! $this->buscandoConductor($viaje)) {
            return ResultadoBusqueda::NoAplica;
        }

        // La consulta de candidatos (la más costosa) se hace fuera de la
        // transacción; cada candidato se re-valida con su fila bloqueada.
        $candidatos = $this->candidatos($viaje, self::radioDeBusqueda($reintento));

        return DB::transaction(function () use ($viajeId, $candidatos): ResultadoBusqueda {
            $viaje = Viaje::query()->lockForUpdate()->find($viajeId);

            if (! $this->buscandoConductor($viaje)) {
                return ResultadoBusqueda::NoAplica;
            }

            $pendiente = $viaje->asignaciones()->pendientes()->lockForUpdate()->first();

            if ($pendiente !== null) {
                if (! $pendiente->vencida()) {
                    return ResultadoBusqueda::OfertaEnCurso;
                }

                $this->cerrarOferta($pendiente, EstadoAsignacion::Expirada);
            }

            $ofertasRealizadas = $viaje->asignaciones()->count();

            if ($ofertasRealizadas >= (int) config('remiseria.asignacion.max_ofertas')) {
                $this->marcarAgotada($viaje, 'Se alcanzó el máximo de ofertas sin que un conductor aceptara.');

                return ResultadoBusqueda::Agotada;
            }

            foreach ($candidatos as $candidato) {
                $asignacion = $this->ofrecerSiSigueDisponible($viaje, $candidato, $ofertasRealizadas + 1);

                if ($asignacion !== null) {
                    return ResultadoBusqueda::Ofrecida;
                }
            }

            return ResultadoBusqueda::SinCandidatos;
        });
    }

    /**
     * Conductores elegibles para el viaje, ordenados por puntaje.
     *
     * Solo se consideran conductores activos, en servicio, con vehículo,
     * sin viaje activo, sin otra oferta pendiente, a los que no se les haya
     * ofrecido ya este viaje y cuyo último ping GPS sea reciente y esté
     * dentro del radio de búsqueda. Un conductor sin ubicación reciente no
     * se considera: no hay forma de saber si sigue cerca.
     *
     * @return Collection<int, Candidato>
     */
    public function candidatos(Viaje $viaje, ?int $radioMetros = null): Collection
    {
        $config = config('remiseria.asignacion');
        $pesos = $config['pesos'];

        $conductores = Conductor::query()
            ->disponibles()
            ->where('ubicacion_actualizada_en', '>=', now()->subSeconds((int) $config['ubicacion_vigencia_segundos']))
            ->whereDoesntHave('asignaciones', fn ($q) => $q->where('viaje_id', $viaje->id))
            ->whereDoesntHave('asignaciones', fn ($q) => $q->pendientes())
            ->cercanos($viaje->origen, (float) ($radioMetros ?? self::radioDeBusqueda(0)))
            ->withCount(['viajes as viajes_hoy' => fn ($q) => $q
                ->where('estado', EstadoViaje::Finalizado)
                ->where('fecha_viaje', '>=', today())])
            ->limit(50)
            ->get();

        return $conductores
            ->map(function (Conductor $conductor) use ($pesos, $config): Candidato {
                $distanciaKm = (float) $conductor->distancia_metros / 1000;
                $calificacion = $conductor->calificacion !== null
                    ? (float) $conductor->calificacion
                    : (float) $config['calificacion_sin_historial'];
                $antiguedadAnios = min(10.0, (float) $conductor->created_at?->diffInDays(now()) / 365);

                $puntaje = $distanciaKm * $pesos['distancia_km']
                    - $calificacion * $pesos['calificacion']
                    - $antiguedadAnios * $pesos['antiguedad_anio']
                    + (int) $conductor->viajes_hoy * $pesos['viaje_del_dia'];

                return new Candidato($conductor->persona_id, (int) round((float) $conductor->distancia_metros), round($puntaje, 4));
            })
            ->sortBy([['puntaje', 'asc'], ['conductorId', 'asc']])
            ->values();
    }

    /**
     * CU 05: el conductor acepta la oferta vigente.
     *
     * @throws RespuestaAsignacionInvalida
     */
    public function aceptar(int $viajeId, int $conductorId): Viaje
    {
        [$viaje, $error] = DB::transaction(function () use ($viajeId, $conductorId): array {
            [$viaje, $asignacion] = $this->bloquearOferta($viajeId, $conductorId);

            if ($asignacion->vencida()) {
                $this->cerrarOferta($asignacion, EstadoAsignacion::Expirada);
                AsignarConductorJob::dispatch($viaje->id);

                return [$viaje, RespuestaAsignacionInvalida::conflicto('La oferta del viaje expiró.')];
            }

            $conductor = Conductor::query()->lockForUpdate()->findOrFail($conductorId);

            if (! $this->conductorPuedeTomarViaje($conductor)) {
                return [$viaje, RespuestaAsignacionInvalida::conflicto('El conductor no está disponible para aceptar viajes (debe estar activo, en servicio, con vehículo y sin otro viaje activo).')];
            }

            $asignacion->update([
                'estado' => EstadoAsignacion::Aceptada,
                'respondida_en' => now(),
            ]);

            $viaje->update([
                'conductor_id' => $conductor->persona_id,
                'vehiculo_id' => $conductor->vehiculo_id,
                'estado' => EstadoViaje::Aceptado,
            ]);

            return [$viaje, null];
        });

        // Se lanza fuera de la transacción para no revertir lo que sí debe
        // persistir (p. ej. la oferta marcada como expirada).
        if ($error !== null) {
            throw $error;
        }

        return $viaje;
    }

    /**
     * CU 05, excepción 2.a: el conductor rechaza; se ofrece al siguiente.
     *
     * @throws RespuestaAsignacionInvalida
     */
    public function rechazar(int $viajeId, int $conductorId): void
    {
        DB::transaction(function () use ($viajeId, $conductorId): void {
            [$viaje, $asignacion] = $this->bloquearOferta($viajeId, $conductorId);

            $asignacion->update([
                'estado' => $asignacion->vencida() ? EstadoAsignacion::Expirada : EstadoAsignacion::Rechazada,
                'respondida_en' => now(),
            ]);

            AsignarConductorJob::dispatch($viaje->id);
        });
    }

    /**
     * Vence una oferta sin respuesta y reasigna al siguiente candidato.
     *
     * @return bool true si la oferta se venció en esta llamada.
     */
    public function expirar(int $asignacionId): bool
    {
        $viajeId = AsignacionViaje::query()->whereKey($asignacionId)->value('viaje_id');

        if ($viajeId === null) {
            return false;
        }

        return DB::transaction(function () use ($viajeId, $asignacionId): bool {
            Viaje::query()->lockForUpdate()->find($viajeId);
            $asignacion = AsignacionViaje::query()->lockForUpdate()->find($asignacionId);

            if ($asignacion === null
                || $asignacion->estado !== EstadoAsignacion::Pendiente
                || ! $asignacion->vencida()) {
                return false;
            }

            $this->cerrarOferta($asignacion, EstadoAsignacion::Expirada);
            AsignarConductorJob::dispatch($viajeId);

            return true;
        });
    }

    /**
     * Cancela las ofertas pendientes de un viaje (p. ej. porque el cliente lo
     * canceló). Debe llamarse con la fila del viaje ya bloqueada.
     */
    public function retirarOfertas(Viaje $viaje): void
    {
        $viaje->asignaciones()->pendientes()->lockForUpdate()->get()
            ->each(fn (AsignacionViaje $asignacion) => $this->cerrarOferta($asignacion, EstadoAsignacion::Cancelada));
    }

    /**
     * Marca la búsqueda como agotada y avisa al panel de despacho.
     */
    public function marcarAgotada(Viaje $viaje, string $motivo): void
    {
        $viaje->update(['busqueda_agotada_en' => now()]);

        Log::notice('Búsqueda de conductor agotada.', ['viaje_id' => $viaje->id, 'motivo' => $motivo]);

        BusquedaDeConductorAgotada::dispatch($viaje->id, $motivo);
    }

    /**
     * Reinicia la búsqueda de un viaje agotado (acción del administrador).
     */
    public function reiniciarBusqueda(Viaje $viaje): bool
    {
        $reiniciada = DB::transaction(function () use ($viaje): bool {
            $actual = Viaje::query()->lockForUpdate()->find($viaje->id);

            if ($actual === null || $actual->estado !== EstadoViaje::Solicitado) {
                return false;
            }

            $actual->update(['busqueda_agotada_en' => null]);
            AsignarConductorJob::dispatch($actual->id);

            return true;
        });

        return $reiniciada;
    }

    /**
     * Radio de búsqueda para el reintento N: parte de radio_busqueda_metros
     * y se amplía radio_expansion_paso_metros por cada búsqueda previa sin
     * candidatos, sin superar radio_maximo_metros.
     */
    public static function radioDeBusqueda(int $reintento): int
    {
        $config = config('remiseria.asignacion');
        $base = (int) $config['radio_busqueda_metros'];

        return min(
            max($base, (int) $config['radio_maximo_metros']),
            $base + max(0, $reintento) * (int) $config['radio_expansion_paso_metros'],
        );
    }

    private function buscandoConductor(?Viaje $viaje): bool
    {
        return $viaje !== null
            && $viaje->estado === EstadoViaje::Solicitado
            && $viaje->busqueda_agotada_en === null;
    }

    private function ofrecerSiSigueDisponible(Viaje $viaje, Candidato $candidato, int $intento): ?AsignacionViaje
    {
        $conductor = Conductor::query()->lockForUpdate()->find($candidato->conductorId);

        if ($conductor === null
            || ! $this->conductorPuedeTomarViaje($conductor)
            || $conductor->asignaciones()->pendientes()->exists()) {
            return null;
        }

        try {
            $asignacion = AsignacionViaje::query()->create([
                'viaje_id' => $viaje->id,
                'conductor_id' => $conductor->persona_id,
                'estado' => EstadoAsignacion::Pendiente,
                'intento' => $intento,
                'distancia_metros' => $candidato->distanciaMetros,
                'puntaje' => $candidato->puntaje,
                'expira_en' => now()->addSeconds((int) config('remiseria.asignacion.tiempo_aceptacion_segundos')),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra transacción le ofreció un viaje a este conductor (o este
            // viaje a otro) entre la lectura y el insert: se prueba el
            // siguiente candidato. En MySQL un insert fallido no aborta la
            // transacción.
            return null;
        }

        $asignacion->setRelation('viaje', $viaje);

        AsignacionOfrecida::dispatch($asignacion);
        ExpirarAsignacionJob::dispatch($asignacion->id)
            ->delay($asignacion->expira_en->copy()->addSeconds(2));

        return $asignacion;
    }

    private function conductorPuedeTomarViaje(Conductor $conductor): bool
    {
        return $conductor->estado === EstadoConductor::Activo
            && $conductor->en_servicio
            && $conductor->vehiculo_id !== null
            && ! Viaje::query()
                ->where('conductor_id', $conductor->persona_id)
                ->whereIn('estado', [EstadoViaje::Aceptado, EstadoViaje::EnCurso])
                ->exists();
    }

    /**
     * @return array{0: Viaje, 1: AsignacionViaje}
     *
     * @throws RespuestaAsignacionInvalida
     */
    private function bloquearOferta(int $viajeId, int $conductorId): array
    {
        $viaje = Viaje::query()->lockForUpdate()->findOrFail($viajeId);

        $asignacion = AsignacionViaje::query()
            ->where('viaje_id', $viajeId)
            ->where('conductor_id', $conductorId)
            ->lockForUpdate()
            ->first();

        if ($asignacion === null) {
            throw RespuestaAsignacionInvalida::noEsDestinatario();
        }

        if ($asignacion->estado !== EstadoAsignacion::Pendiente || $viaje->estado !== EstadoViaje::Solicitado) {
            throw RespuestaAsignacionInvalida::conflicto('La oferta de este viaje ya no está vigente.');
        }

        return [$viaje, $asignacion];
    }

    private function cerrarOferta(AsignacionViaje $asignacion, EstadoAsignacion $estado): void
    {
        $asignacion->update(['estado' => $estado]);

        AsignacionRetirada::dispatch($asignacion->id, $asignacion->viaje_id, $asignacion->conductor_id, $estado->value);
    }
}
