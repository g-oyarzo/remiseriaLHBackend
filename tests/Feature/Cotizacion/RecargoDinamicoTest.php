<?php

declare(strict_types=1);

namespace Tests\Feature\Cotizacion;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\Services\Clima\OpenMeteoProveedorClima;
use App\Services\Clima\ProveedorClima;
use App\Services\Cotizacion\Cotizacion;
use App\Services\Cotizacion\CotizadorService;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\Ruta;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Precio dinámico por demanda y clima (decisión del negocio), aplicado
 * sobre el precio cerrado de la cotización.
 */
class RecargoDinamicoTest extends TestCase
{
    use RefreshDatabase;

    private Coordinate $origen;

    private Coordinate $destino;

    protected function setUp(): void
    {
        parent::setUp();

        config(['remiseria.asignacion.radio_busqueda_metros' => 10000]);
        $this->origen = new Coordinate(lat: -34.9214, lng: -57.9544);
        $this->destino = new Coordinate(lat: -34.9055, lng: -57.9325);

        // Ruta de 3 km: 1500 + 3 * 350 = 2550,00 antes del recargo.
        $this->app->instance(ProveedorDistancia::class, new class implements ProveedorDistancia
        {
            public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
            {
                return new Ruta(3000, 420, 'fake');
            }
        });
        $this->conLluvia(null);
    }

    private function conLluvia(?float $milimetros): void
    {
        $this->app->instance(ProveedorClima::class, new class($milimetros) implements ProveedorClima
        {
            public function __construct(private ?float $milimetros) {}

            public function precipitacionMm(Coordinate $punto): ?float
            {
                return $this->milimetros;
            }
        });
    }

    private function tarifa(array $recargo = []): Tarifa
    {
        return Tarifa::factory()->create($recargo + ['precio_base' => 1500, 'precio_por_km' => 350]);
    }

    /**
     * $pendientes solicitudes sin conductor y $disponibles conductores con
     * GPS vigente, todos cerca del origen.
     */
    private function demanda(int $pendientes, int $disponibles): void
    {
        Viaje::factory()->count($pendientes)->create(['estado' => EstadoViaje::Solicitado, 'origen' => $this->origen, 'tarifa_id' => null]);

        for ($i = 0; $i < $disponibles; $i++) {
            Conductor::factory()->disponibleEn($this->origen)->create();
        }
    }

    private function cotizar(bool $reserva = false): Cotizacion
    {
        return app(CotizadorService::class)->cotizar($this->origen, $this->destino, $reserva);
    }

    public function test_sin_configurar_no_hay_recargo(): void
    {
        $this->tarifa();
        $this->demanda(10, 1);
        $this->conLluvia(20.0);

        $cotizacion = $this->cotizar();

        $this->assertSame('2550.00', $cotizacion->monto);
        $this->assertSame('0.00', $cotizacion->toArray()['recargo']['porcentaje']);
    }

    public function test_alta_demanda_aplica_el_recargo_configurado(): void
    {
        $this->tarifa(['demanda_umbral_ratio' => 2, 'demanda_recargo_pct' => 20]);
        $this->demanda(3, 1);

        $cotizacion = $this->cotizar();

        $this->assertSame('3060.00', $cotizacion->monto);
        $this->assertSame('2550.00', $cotizacion->montoBase);
        $this->assertSame('20.00', $cotizacion->toArray()['recargo']['porcentaje']);
        $this->assertSame(3.0, $cotizacion->toArray()['recargo']['ratio_demanda']);
        $this->assertSame(['Alta demanda en la zona.'], $cotizacion->toArray()['recargo']['motivos']);
    }

    public function test_demanda_bajo_el_umbral_no_tiene_recargo(): void
    {
        $this->tarifa(['demanda_umbral_ratio' => 2, 'demanda_recargo_pct' => 20]);
        $this->demanda(3, 2);

        $this->assertSame('2550.00', $this->cotizar()->monto);
    }

    public function test_solo_cuenta_la_demanda_reciente_y_cercana(): void
    {
        $this->tarifa(['demanda_umbral_ratio' => 2, 'demanda_recargo_pct' => 20]);
        Conductor::factory()->disponibleEn($this->origen)->create();
        // Lejos (Mar del Plata) y vieja (hace 2 horas): no cuentan.
        Viaje::factory()->count(5)->create(['estado' => EstadoViaje::Solicitado, 'origen' => new Coordinate(-38.0055, -57.5426), 'tarifa_id' => null]);
        Viaje::factory()->count(5)->create(['estado' => EstadoViaje::Solicitado, 'origen' => $this->origen, 'created_at' => now()->subHours(2), 'tarifa_id' => null]);

        $this->assertSame('2550.00', $this->cotizar()->monto);
    }

    public function test_lluvia_aplica_el_recargo_por_clima(): void
    {
        $this->tarifa(['lluvia_umbral_mm' => 0.1, 'lluvia_recargo_pct' => 10]);
        $this->conLluvia(1.5);

        $cotizacion = $this->cotizar();

        $this->assertSame('2805.00', $cotizacion->monto);
        $this->assertSame(['Lluvia en la zona de origen.'], $cotizacion->toArray()['recargo']['motivos']);
    }

    public function test_sin_dato_de_clima_no_hay_recargo_por_lluvia(): void
    {
        $this->tarifa(['lluvia_umbral_mm' => 0.1, 'lluvia_recargo_pct' => 10]);
        $this->conLluvia(null);

        $this->assertSame('2550.00', $this->cotizar()->monto);
    }

    public function test_demanda_y_lluvia_se_combinan_y_se_acotan_con_el_maximo(): void
    {
        $this->tarifa([
            'demanda_umbral_ratio' => 1, 'demanda_recargo_pct' => 20,
            'lluvia_umbral_mm' => 0.1, 'lluvia_recargo_pct' => 10,
            'recargo_maximo_pct' => 50,
        ]);
        $this->demanda(2, 1);
        $this->conLluvia(3.0);

        // 1,20 * 1,10 = 1,32 -> 2550 * 1,32 = 3366,00
        $this->assertSame('3366.00', $this->cotizar()->monto);

        Tarifa::query()->update(['recargo_maximo_pct' => 25]);
        Tarifa::olvidarVigenteEnCache();

        $this->assertSame('3187.50', $this->cotizar()->monto);
    }

    public function test_las_reservas_no_tienen_recargo(): void
    {
        $this->tarifa(['demanda_umbral_ratio' => 1, 'demanda_recargo_pct' => 20, 'lluvia_recargo_pct' => 10]);
        $this->demanda(5, 1);
        $this->conLluvia(5.0);

        $this->assertSame('2550.00', $this->cotizar(reserva: true)->monto);
    }

    public function test_el_viaje_guarda_el_recargo_y_se_cobra_el_precio_cerrado(): void
    {
        $this->tarifa(['demanda_umbral_ratio' => 2, 'demanda_recargo_pct' => 20]);
        $this->demanda(3, 1);
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $cliente->persona_id]);

        $id = $this->actingAs($cliente)->postJson('/api/v1/cliente/viajes/solicitar', [
            'origen_lat' => $this->origen->lat,
            'origen_lng' => $this->origen->lng,
            'destino_lat' => $this->destino->lat,
            'destino_lng' => $this->destino->lng,
            'metodo_pago' => 'efectivo',
        ])
            ->assertCreated()
            ->assertJsonPath('data.costo', '3060.00')
            ->assertJsonPath('data.monto_base_cotizado', '2550.00')
            ->assertJsonPath('data.recargo_dinamico_pct', '20.00')
            ->json('data.id');

        $this->assertSame(['Alta demanda en la zona.'], Viaje::query()->findOrFail($id)->recargo_dinamico_detalle['motivos']);
    }

    public function test_el_endpoint_de_cotizacion_informa_el_recargo_y_admite_reservas(): void
    {
        $this->tarifa(['demanda_umbral_ratio' => 1, 'demanda_recargo_pct' => 20]);
        $this->demanda(2, 1);
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        $datos = [
            'origen_lat' => $this->origen->lat, 'origen_lng' => $this->origen->lng,
            'destino_lat' => $this->destino->lat, 'destino_lng' => $this->destino->lng,
        ];

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', $datos)
            ->assertOk()
            ->assertJsonPath('data.monto', '3060.00')
            ->assertJsonPath('data.monto_base', '2550.00')
            ->assertJsonPath('data.recargo.porcentaje', '20.00');

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', $datos + ['reserva' => true])
            ->assertOk()
            ->assertJsonPath('data.monto', '2550.00');
    }

    public function test_el_admin_configura_el_recargo_y_se_conserva_si_no_se_envia(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        Tarifa::factory()->create(['precio_base' => 1000, 'precio_por_km' => 300]);

        $this->actingAs($admin)->putJson('/api/v1/admin/tarifas', [
            'precio_base' => 1000, 'precio_por_km' => 300,
            'demanda_umbral_ratio' => 2.5, 'demanda_recargo_pct' => 15, 'lluvia_recargo_pct' => 10,
        ])->assertOk()->assertJsonPath('data.demanda_recargo_pct', '15.00');

        // Un cambio solo de precios conserva los parámetros de recargo.
        $this->actingAs($admin)->putJson('/api/v1/admin/tarifas', ['precio_base' => 1200, 'precio_por_km' => 320])
            ->assertOk()
            ->assertJsonPath('data.demanda_umbral_ratio', '2.50')
            ->assertJsonPath('data.demanda_recargo_pct', '15.00')
            ->assertJsonPath('data.lluvia_recargo_pct', '10.00');

        $this->actingAs($admin)->putJson('/api/v1/admin/tarifas', [
            'precio_base' => 1200, 'precio_por_km' => 320, 'demanda_recargo_pct' => 301,
        ])->assertUnprocessable()->assertJsonValidationErrors('demanda_recargo_pct');
    }

    public function test_open_meteo_devuelve_la_precipitacion_actual(): void
    {
        config(['services.clima.cache_minutos' => 10]);
        Http::fake(['clima.test/*' => Http::response(['current' => ['time' => '2026-11-04T10:00', 'precipitation' => 1.2]])]);
        $proveedor = new OpenMeteoProveedorClima('https://clima.test/v1/forecast', 'CLAVE');

        $this->assertSame(1.2, $proveedor->precipitacionMm($this->origen));
        $this->assertSame(1.2, $proveedor->precipitacionMm(new Coordinate(-34.9201, -57.9533)));

        // Una sola consulta por zona (cache) y con la API key del plan comercial.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => $r['current'] === 'precipitation' && $r['apikey'] === 'CLAVE');
    }

    public function test_open_meteo_caido_o_con_respuesta_invalida_no_aplica_recargo(): void
    {
        Http::fake(['clima.test/*' => Http::sequence()->push('error', 503)->push(['current' => []])]);
        $proveedor = new OpenMeteoProveedorClima('https://clima.test/v1/forecast');

        $this->assertNull($proveedor->precipitacionMm($this->origen));
        $this->assertNull($proveedor->precipitacionMm(new Coordinate(-30.0, -60.0)));
    }
}
