<?php

declare(strict_types=1);

namespace App\Services\Cotizacion;

use App\Casts\PointCast;
use App\Enums\EstadoViaje;
use App\Models\Conductor;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\Services\Clima\ProveedorClima;
use App\Support\Dinero;
use App\ValueObjects\Coordinate;

/**
 * Recargo por demanda y por clima sobre la tarifa (decisión del negocio).
 *
 * - Demanda: solicitudes sin conductor cerca del origen (últimos 30 min)
 *   divididas por los conductores disponibles con GPS vigente en el mismo
 *   radio. Si el cociente alcanza demanda_umbral_ratio, se suma
 *   demanda_recargo_pct.
 * - Clima: si la precipitación en el origen alcanza lluvia_umbral_mm, se
 *   suma lluvia_recargo_pct.
 * - Ambos se combinan de forma multiplicativa y se acotan con
 *   recargo_maximo_pct.
 *
 * Las reservas no llevan recargo: la demanda y el clima del momento de la
 * cotización no son los del día del viaje.
 */
final class RecargoDinamico
{
    private const VENTANA_DEMANDA_MINUTOS = 30;

    public function __construct(private readonly ProveedorClima $clima) {}

    public function calcular(Tarifa $tarifa, Coordinate $origen, bool $esReserva): Recargo
    {
        if ($esReserva) {
            return Recargo::ninguno(['Las reservas no tienen recargo dinámico.']);
        }

        $motivos = [];
        $demandaPb = 0;
        $climaPb = 0;
        $ratio = null;
        $precipitacion = null;

        // Un porcentaje con dos decimales ("15.25") convertido "a centavos"
        // da exactamente sus puntos básicos (1525).
        $recargoDemanda = Dinero::aCentavos((string) $tarifa->demanda_recargo_pct);
        if ($tarifa->demanda_umbral_ratio !== null && $recargoDemanda > 0) {
            $ratio = $this->ratioDeDemanda($origen);

            if ($ratio >= (float) $tarifa->demanda_umbral_ratio) {
                $demandaPb = $recargoDemanda;
                $motivos[] = 'Alta demanda en la zona.';
            }
        }

        $recargoClima = Dinero::aCentavos((string) $tarifa->lluvia_recargo_pct);
        if ($recargoClima > 0) {
            $precipitacion = $this->clima->precipitacionMm($origen);

            if ($precipitacion !== null && $precipitacion >= (float) $tarifa->lluvia_umbral_mm) {
                $climaPb = $recargoClima;
                $motivos[] = 'Lluvia en la zona de origen.';
            }
        }

        // (1 + d)(1 + c) - 1, en puntos básicos.
        $combinado = $demandaPb + $climaPb + intdiv($demandaPb * $climaPb, 10_000);
        $total = min($combinado, Dinero::aCentavos((string) $tarifa->recargo_maximo_pct));

        return new Recargo($total, $demandaPb, $climaPb, $ratio !== null ? round($ratio, 2) : null, $precipitacion, $motivos);
    }

    private function ratioDeDemanda(Coordinate $origen): float
    {
        $radio = (float) config('remiseria.asignacion.radio_busqueda_metros');
        $punto = PointCast::SQL_PUNTO_DESDE_WKT;

        $pendientes = Viaje::query()
            ->where('estado', EstadoViaje::Solicitado)
            ->whereNull('conductor_id')
            ->where('created_at', '>=', now()->subMinutes(self::VENTANA_DEMANDA_MINUTOS))
            ->whereRaw("ST_Distance_Sphere(origen, {$punto}) <= ?", [$origen->toWkt(), $radio])
            ->count();

        $disponibles = Conductor::query()
            ->disponibles()
            ->where('ubicacion_actualizada_en', '>=', now()->subSeconds((int) config('remiseria.asignacion.ubicacion_vigencia_segundos')))
            ->whereRaw("ST_Distance_Sphere(ubicacion_actual, {$punto}) <= ?", [$origen->toWkt(), $radio])
            ->count();

        return $pendientes / max(1, $disponibles);
    }
}
