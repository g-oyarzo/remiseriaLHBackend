<?php

declare(strict_types=1);

namespace App\Services\Viajes;

use App\Casts\PointCast;
use App\Enums\EstadoViaje;
use App\Models\Conductor;
use App\Models\Viaje;
use App\Services\Geolocalizacion\RegistroDeUbicaciones;
use App\Support\Dinero;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * CU 06 - Finalizar viaje.
 *
 * La posición contra la que se valida la llegada es la última registrada en
 * el servidor (pings GPS), no un valor libre del request: si el conductor
 * envía su posición junto con la finalización, entra como un ping más, con
 * la misma validación de plausibilidad.
 *
 * El importe a cobrar es el cotizado al solicitar el viaje (precio
 * cerrado): ni el recorrido real ni un cambio de tarifa posterior lo alteran.
 */
final class FinalizacionDeViajes
{
    public function __construct(private readonly RegistroDeUbicaciones $ubicaciones) {}

    /**
     * @throws FinalizacionNoPermitida
     */
    public function finalizar(Viaje $viaje, Conductor $conductor): Viaje
    {
        $config = config('remiseria.finalizacion');

        if ($viaje->estado !== EstadoViaje::EnCurso) {
            throw new FinalizacionNoPermitida('El viaje debe estar "en curso" para finalizarlo.', Response::HTTP_CONFLICT);
        }

        $ubicacion = $this->ubicaciones->ultima($conductor);

        if ($ubicacion === null || $ubicacion->antiguedadSegundos() > (int) $config['ubicacion_vigencia_segundos']) {
            throw new FinalizacionNoPermitida(
                'No hay una ubicación reciente del conductor. Envíe su posición actual para finalizar el viaje.',
                Response::HTTP_CONFLICT,
            );
        }

        $distanciaAlDestino = $this->distanciaAlDestino($viaje, $ubicacion->coordenada->toWkt());

        if ($distanciaAlDestino > (float) $config['radio_tolerancia_metros']) {
            throw new FinalizacionNoPermitida(
                'Todavía no llegó al destino del viaje.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['distancia_al_destino_metros' => (int) round($distanciaAlDestino)],
            );
        }

        $finalizado = DB::transaction(function () use ($viaje, $conductor, $distanciaAlDestino): ?Viaje {
            $actual = Viaje::query()->lockForUpdate()->findOrFail($viaje->id);

            if ($actual->estado !== EstadoViaje::EnCurso || $actual->conductor_id !== $conductor->persona_id) {
                return null;
            }

            $actual->update($this->cierre($actual) + [
                'estado' => EstadoViaje::Finalizado,
                'finalizado_en' => now(),
                'distancia_al_destino_metros' => (int) round($distanciaAlDestino),
            ]);

            return $actual;
        });

        if ($finalizado === null) {
            throw new FinalizacionNoPermitida('El viaje ya no está en curso.', Response::HTTP_CONFLICT);
        }

        return $finalizado;
    }

    /**
     * Distancia en metros entre un punto y el destino del viaje, calculada
     * por MySQL con ST_Distance_Sphere.
     */
    private function distanciaAlDestino(Viaje $viaje, string $wkt): float
    {
        $fila = DB::selectOne(
            'SELECT ST_Distance_Sphere(destino, '.PointCast::SQL_PUNTO_DESDE_WKT.') AS metros FROM viajes WHERE id = ?',
            [$wkt, $viaje->id],
        );

        return (float) $fila->metros;
    }

    /**
     * Distancia, duración y monto finales.
     *
     * Precio cerrado (decisión del negocio): se cobra siempre lo cotizado al
     * solicitar el viaje. La distancia y la duración reales se registran
     * como información del viaje, pero no modifican el importe.
     *
     * @return array<string, mixed>
     */
    private function cierre(Viaje $viaje): array
    {
        $duracion = $viaje->iniciado_en !== null
            ? (int) ceil($viaje->iniciado_en->diffInSeconds(now()) / 60)
            : null;

        $seguimientoConfiable = $viaje->puntos_seguimiento >= (int) config('remiseria.finalizacion.minimo_puntos_seguimiento')
            && $viaje->distancia_recorrida_metros > 0;

        $costoFinal = $viaje->costo !== null ? Dinero::desdeCentavos(Dinero::aCentavos((string) $viaje->costo)) : null;

        return [
            'duracion_final_min' => $duracion,
            'distancia_final_km' => $seguimientoConfiable
                ? round($viaje->distancia_recorrida_metros / 1000, 3)
                : $viaje->distancia_estimada_km,
            'costo_final' => $costoFinal,
            'costo_final_origen' => $costoFinal !== null ? 'cotizado' : null,
        ];
    }
}
