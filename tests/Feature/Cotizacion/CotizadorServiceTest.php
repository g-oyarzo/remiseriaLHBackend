<?php

declare(strict_types=1);

namespace Tests\Feature\Cotizacion;

use App\Enums\MetodoCotizacion;
use App\Models\Tarifa;
use App\Models\ZonaTarifaria;
use App\Services\Cotizacion\CotizacionNoDisponible;
use App\Services\Cotizacion\CotizadorService;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\Ruta;
use App\Services\Mapas\ServicioMapasNoDisponible;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizadorServiceTest extends TestCase
{
    use RefreshDatabase;

    private Coordinate $origen;

    private Coordinate $destino;

    protected function setUp(): void
    {
        parent::setUp();

        // ~2,2 km en línea recta, en La Plata.
        $this->origen = new Coordinate(lat: -34.9214, lng: -57.9544);
        $this->destino = new Coordinate(lat: -34.9014, lng: -57.9544);
    }

    private function cotizadorConRuta(int $metros, int $segundos = 600): CotizadorService
    {
        return new CotizadorService(new class($metros, $segundos) implements ProveedorDistancia
        {
            public function __construct(private int $metros, private int $segundos) {}

            public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
            {
                return new Ruta($this->metros, $this->segundos, 'fake');
            }
        });
    }

    private function cotizadorConMapasCaidos(): CotizadorService
    {
        return new CotizadorService(new class implements ProveedorDistancia
        {
            public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
            {
                throw new ServicioMapasNoDisponible('timeout');
            }
        });
    }

    public function test_aplica_la_formula_tarifa_base_mas_distancia_por_precio_km(): void
    {
        $tarifa = Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);

        $cotizacion = $this->cotizadorConRuta(7650, 845)->cotizar($this->origen, $this->destino);

        // 1500 + 7,65 km * 350 = 4177,50
        $this->assertSame('4177.50', $cotizacion->monto);
        $this->assertSame(7.65, $cotizacion->distanciaKm);
        $this->assertSame(15, $cotizacion->duracionMinutos);
        $this->assertSame(MetodoCotizacion::Mapa, $cotizacion->metodo);
        $this->assertSame($tarifa->id, $cotizacion->tarifaId);
        $this->assertSame('1500.00', $cotizacion->precioBase);
        $this->assertSame('350.00', $cotizacion->precioPorKm);
    }

    public function test_redondea_al_centavo_sin_errores_de_punto_flotante(): void
    {
        Tarifa::factory()->create(['precio_base' => '1000.10', 'precio_por_km' => '333.33']);

        // 333,33 * 1,001 km = 333,66333 -> 333,66 ; + 1000,10 = 1333,76
        $this->assertSame('1333.76', $this->cotizadorConRuta(1001)->cotizar($this->origen, $this->destino)->monto);
    }

    public function test_monto_para_distancia_es_exacto_en_valores_limite(): void
    {
        $this->assertSame('1500.00', CotizadorService::montoParaDistancia('1500.00', '350.00', 0));
        $this->assertSame('1500.35', CotizadorService::montoParaDistancia('1500.00', '350.00', 1));
        $this->assertSame('0.03', CotizadorService::montoParaDistancia('0.01', '0.01', 1500));
        $this->assertSame('99999999.99', CotizadorService::montoParaDistancia('99999999.99', '0.01', 0));
    }

    public function test_si_el_mapa_falla_usa_el_monto_fijo_de_la_zona(): void
    {
        Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);
        ZonaTarifaria::factory()->create(['distancia_max_km' => 1, 'monto' => 1000]);
        $zona = ZonaTarifaria::factory()->create(['distancia_max_km' => 5, 'monto' => '3200.00']);

        $cotizacion = $this->cotizadorConMapasCaidos()->cotizar($this->origen, $this->destino);

        $this->assertSame(MetodoCotizacion::Zona, $cotizacion->metodo);
        $this->assertSame('3200.00', $cotizacion->monto);
        $this->assertSame($zona->id, $cotizacion->zonaTarifariaId);
        $this->assertNull($cotizacion->duracionMinutos);
    }

    public function test_si_ninguna_zona_cubre_el_viaje_estima_con_la_distancia_lineal_corregida(): void
    {
        config(['remiseria.factor_correccion_distancia' => 1.5]);
        Tarifa::factory()->create(['precio_base' => 1000, 'precio_por_km' => 100]);
        ZonaTarifaria::factory()->create(['distancia_max_km' => 1, 'monto' => 900]);

        $cotizacion = $this->cotizadorConMapasCaidos()->cotizar($this->origen, $this->destino);

        $lineal = $this->origen->distanciaEnMetrosHacia($this->destino);
        $estimadaMetros = (int) round($lineal * 1.5);

        $this->assertSame(MetodoCotizacion::EstimacionLineal, $cotizacion->metodo);
        $this->assertSame(CotizadorService::montoParaDistancia('1000.00', '100.00', $estimadaMetros), $cotizacion->monto);
        $this->assertEqualsWithDelta($estimadaMetros / 1000, $cotizacion->distanciaKm, 0.001);
    }

    public function test_las_zonas_inactivas_no_se_usan(): void
    {
        Tarifa::factory()->create();
        ZonaTarifaria::factory()->create(['distancia_max_km' => 10, 'monto' => 1, 'activa' => false]);

        $this->assertSame(
            MetodoCotizacion::EstimacionLineal,
            $this->cotizadorConMapasCaidos()->cotizar($this->origen, $this->destino)->metodo,
        );
    }

    public function test_sin_tarifa_vigente_no_cotiza(): void
    {
        try {
            $this->cotizadorConRuta(5000)->cotizar($this->origen, $this->destino);
            $this->fail('Se esperaba CotizacionNoDisponible.');
        } catch (CotizacionNoDisponible $e) {
            $this->assertTrue($e->faltaConfiguracion);
        }
    }

    public function test_origen_y_destino_iguales_no_se_cotizan(): void
    {
        Tarifa::factory()->create();

        $this->expectException(CotizacionNoDisponible::class);

        $this->cotizadorConRuta(0)->cotizar($this->origen, new Coordinate(lat: -34.92141, lng: -57.95441));
    }

    public function test_un_viaje_mas_largo_que_el_maximo_no_se_cotiza(): void
    {
        config(['remiseria.cotizacion.distancia_maxima_km' => 100]);
        Tarifa::factory()->create();

        $this->expectException(CotizacionNoDisponible::class);

        // La Plata -> Mar del Plata, ~ 360 km en línea recta.
        $this->cotizadorConRuta(400_000)->cotizar($this->origen, new Coordinate(lat: -38.0055, lng: -57.5426));
    }
}
