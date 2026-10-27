<?php

declare(strict_types=1);

namespace App\Services\Cotizacion;

use App\Enums\MetodoCotizacion;
use App\Models\Tarifa;
use App\Models\ZonaTarifaria;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\ServicioMapasNoDisponible;
use App\Support\Dinero;
use App\ValueObjects\Coordinate;

/**
 * CU 02 - Cotizar viaje: Costo = TarifaBase + (Distancia * PrecioKm).
 *
 * La distancia se obtiene del servicio externo de mapas. Si falla o no
 * responde a tiempo, se aplica la contingencia: el monto fijo de la zona
 * tarifaria más chica que cubra la distancia en línea recta y, si ninguna
 * zona la cubre, la fórmula sobre la distancia en línea recta corregida.
 * El método aplicado queda registrado en la cotización.
 *
 * Todos los montos se calculan en centavos enteros.
 */
final class CotizadorService
{
    public function __construct(private readonly ProveedorDistancia $mapas) {}

    /**
     * @throws CotizacionNoDisponible
     */
    public function cotizar(Coordinate $origen, Coordinate $destino): Cotizacion
    {
        $distanciaLinealMetros = $origen->distanciaEnMetrosHacia($destino);

        if ($distanciaLinealMetros < (float) config('remiseria.cotizacion.distancia_minima_metros')) {
            throw new CotizacionNoDisponible('El origen y el destino deben ser distintos.');
        }

        if ($distanciaLinealMetros / 1000 > (float) config('remiseria.cotizacion.distancia_maxima_km')) {
            throw new CotizacionNoDisponible('La distancia del viaje excede el máximo permitido.');
        }

        $tarifa = Tarifa::vigente() ?? throw CotizacionNoDisponible::sinTarifaVigente();

        try {
            $ruta = $this->mapas->calcularRuta($origen, $destino);
        } catch (ServicioMapasNoDisponible) {
            return $this->cotizarPorContingencia($tarifa, $distanciaLinealMetros);
        }

        return new Cotizacion(
            monto: $this->aplicarFormula($tarifa, $ruta->distanciaMetros),
            distanciaKm: $ruta->distanciaKm(),
            duracionMinutos: $ruta->duracionMinutos(),
            metodo: MetodoCotizacion::Mapa,
            tarifaId: $tarifa->id,
            precioBase: (string) $tarifa->precio_base,
            precioPorKm: (string) $tarifa->precio_por_km,
            proveedorMapas: $ruta->proveedor,
        );
    }

    /**
     * Monto para una distancia dada con los valores de una tarifa. Se usa
     * también al finalizar el viaje con los valores guardados en el viaje.
     */
    public static function montoParaDistancia(string $precioBase, string $precioPorKm, int $distanciaMetros): string
    {
        $centavos = Dinero::aCentavos($precioBase)
            + (int) round($distanciaMetros * Dinero::aCentavos($precioPorKm) / 1000);

        return Dinero::desdeCentavos($centavos);
    }

    private function aplicarFormula(Tarifa $tarifa, int $distanciaMetros): string
    {
        return self::montoParaDistancia((string) $tarifa->precio_base, (string) $tarifa->precio_por_km, $distanciaMetros);
    }

    private function cotizarPorContingencia(Tarifa $tarifa, float $distanciaLinealMetros): Cotizacion
    {
        $distanciaLinealKm = $distanciaLinealMetros / 1000;
        $zona = ZonaTarifaria::paraDistancia($distanciaLinealKm);

        // La distancia informada es una estimación de manejo (línea recta
        // por el factor de corrección); no hay tiempo estimado sin ruteo.
        $distanciaEstimadaMetros = (int) round($distanciaLinealMetros * (float) config('remiseria.factor_correccion_distancia'));

        return new Cotizacion(
            monto: $zona !== null
                ? Dinero::desdeCentavos(Dinero::aCentavos((string) $zona->monto))
                : $this->aplicarFormula($tarifa, $distanciaEstimadaMetros),
            distanciaKm: round($distanciaEstimadaMetros / 1000, 3),
            duracionMinutos: null,
            metodo: $zona !== null ? MetodoCotizacion::Zona : MetodoCotizacion::EstimacionLineal,
            tarifaId: $tarifa->id,
            precioBase: (string) $tarifa->precio_base,
            precioPorKm: (string) $tarifa->precio_por_km,
            zonaTarifariaId: $zona?->id,
        );
    }
}
