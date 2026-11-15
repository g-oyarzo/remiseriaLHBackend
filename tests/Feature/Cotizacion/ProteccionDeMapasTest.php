<?php

declare(strict_types=1);

namespace Tests\Feature\Cotizacion;

use App\Enums\MetodoCotizacion;
use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\ZonaTarifaria;
use App\Services\Cotizacion\CotizacionNoDisponible;
use App\Services\Cotizacion\CotizadorService;
use App\Services\Mapas\GuardaDeProveedor;
use App\Services\Mapas\OpenRouteServiceProveedorDistancia;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\RutaInexistente;
use App\Services\Mapas\ServicioMapasNoDisponible;
use App\Services\Mapas\SinProveedorDistancia;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Proveedor openrouteservice y protecciones comunes de los proveedores de
 * mapas: cuota local, circuit breaker, recuperación automática y rechazo de
 * trayectos sin recorrido por calles.
 */
class ProteccionDeMapasTest extends TestCase
{
    use RefreshDatabase;

    private const ORS = 'https://ors.test/openrouteservice';

    private Coordinate $origen;

    private Coordinate $destino;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'services.mapas.cache_minutos' => 0,
            'services.mapas.reintentos' => 0,
            'services.mapas.circuito.umbral_fallas' => 3,
            'services.mapas.circuito.segundos_abierto' => 60,
            'services.mapas.circuito.segundos_tras_429' => 300,
            'services.mapas.cuotas.ors' => ['por_minuto' => null, 'por_dia' => null, 'por_mes' => null],
        ]);
        $this->origen = new Coordinate(lat: -34.9214, lng: -57.9544);
        $this->destino = new Coordinate(lat: -34.9055, lng: -57.9325);
    }

    private function ors(): OpenRouteServiceProveedorDistancia
    {
        return new OpenRouteServiceProveedorDistancia('CLAVE-ORS', self::ORS);
    }

    private function rutaOk(float $metros = 3456.7, float $segundos = 512.4): array
    {
        return ['routes' => [['summary' => ['distance' => $metros, 'duration' => $segundos]]]];
    }

    // ---------------------------------------------------------------
    // openrouteservice
    // ---------------------------------------------------------------

    public function test_ors_devuelve_distancia_y_duracion_con_la_clave_en_un_header(): void
    {
        Http::fake(['ors.test/*' => Http::response($this->rutaOk())]);

        $ruta = $this->ors()->calcularRuta($this->origen, $this->destino);

        $this->assertSame(3457, $ruta->distanciaMetros);
        $this->assertSame(512, $ruta->duracionSegundos);
        $this->assertSame('ors', $ruta->proveedor);

        Http::assertSent(fn (Request $r): bool => $r->url() === self::ORS.'/v2/directions/driving-car'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'CLAVE-ORS')
            && ! str_contains($r->url(), 'CLAVE-ORS')
            // ORS espera [longitud, latitud].
            && $r['coordinates'] === [[-57.9544, -34.9214], [-57.9325, -34.9055]]
            && $r['instructions'] === false
            && $r['geometry'] === false);
    }

    public function test_ors_sin_recorrido_por_calles_lanza_ruta_inexistente(): void
    {
        foreach ([2009, 2010] as $codigo) {
            Http::fake(['ors.test/*' => Http::response(['error' => ['code' => $codigo, 'message' => 'x']], 404)]);

            try {
                $this->ors()->calcularRuta($this->origen, $this->destino);
                $this->fail('Se esperaba RutaInexistente.');
            } catch (RutaInexistente) {
                $this->addToAssertionCount(1);
            }
        }

        // No es una falla del servicio: el circuito sigue cerrado.
        $this->assertFalse((new GuardaDeProveedor('ors'))->circuitoAbierto());
    }

    public function test_ors_respuesta_sin_rutas_o_error_http_es_una_falla(): void
    {
        Http::fake(['ors.test/*' => Http::sequence()->push(['routes' => []])->push('error', 500)]);

        foreach ([1, 2] as $_) {
            try {
                $this->ors()->calcularRuta($this->origen, $this->destino);
                $this->fail('Se esperaba ServicioMapasNoDisponible.');
            } catch (RutaInexistente) {
                $this->fail('No debe confundirse una falla con la ausencia de ruta.');
            } catch (ServicioMapasNoDisponible) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_driver_ors_se_resuelve_con_su_clave_y_sin_clave_degrada(): void
    {
        config(['services.mapas.driver' => 'ors', 'services.mapas.ors.api_key' => 'k', 'services.mapas.ors.url' => self::ORS]);
        $this->assertInstanceOf(OpenRouteServiceProveedorDistancia::class, app(ProveedorDistancia::class));

        config(['services.mapas.ors.api_key' => null]);
        $this->assertInstanceOf(SinProveedorDistancia::class, app(ProveedorDistancia::class));

        config(['services.mapas.driver' => 'zonas']);
        $this->assertInstanceOf(SinProveedorDistancia::class, app(ProveedorDistancia::class));
    }

    // ---------------------------------------------------------------
    // Cuota local
    // ---------------------------------------------------------------

    public function test_al_agotar_la_cuota_local_no_llama_al_proveedor(): void
    {
        config(['services.mapas.cuotas.ors.por_dia' => 2]);
        Http::fake(['ors.test/*' => Http::response($this->rutaOk())]);

        $this->ors()->calcularRuta($this->origen, $this->destino);
        $this->ors()->calcularRuta($this->origen, $this->destino);

        try {
            $this->ors()->calcularRuta($this->origen, $this->destino);
            $this->fail('Se esperaba ServicioMapasNoDisponible por cuota agotada.');
        } catch (ServicioMapasNoDisponible $e) {
            $this->assertStringContainsString('límite de consultas', $e->getMessage());
        }

        Http::assertSentCount(2);
        $this->assertSame(2, (new GuardaDeProveedor('ors'))->estado()['dia']);

        // Al día siguiente la cuota se renueva.
        $this->travel(1)->days();
        $this->ors()->calcularRuta($this->origen, $this->destino);
        Http::assertSentCount(3);
    }

    public function test_la_cuota_por_minuto_se_renueva_al_minuto_siguiente(): void
    {
        config(['services.mapas.cuotas.ors.por_minuto' => 1]);
        $this->freezeTime();
        Http::fake(['ors.test/*' => Http::response($this->rutaOk())]);

        $this->ors()->calcularRuta($this->origen, $this->destino);

        try {
            $this->ors()->calcularRuta($this->origen, $this->destino);
            $this->fail('Se esperaba cuota por minuto agotada.');
        } catch (ServicioMapasNoDisponible) {
            $this->addToAssertionCount(1);
        }

        $this->travel(61)->seconds();
        $this->ors()->calcularRuta($this->origen, $this->destino);
        Http::assertSentCount(2);
    }

    public function test_cada_reintento_descuenta_cuota(): void
    {
        config(['services.mapas.reintentos' => 1]);
        Http::fake(['ors.test/*' => Http::sequence()->pushFailedConnection()->push($this->rutaOk())]);

        $this->ors()->calcularRuta($this->origen, $this->destino);

        Http::assertSentCount(2);
        $this->assertSame(2, (new GuardaDeProveedor('ors'))->estado()['dia']);
    }

    public function test_la_cache_no_consume_cuota(): void
    {
        config(['services.mapas.cache_minutos' => 10, 'services.mapas.cuotas.ors.por_dia' => 1]);
        Http::fake(['ors.test/*' => Http::response($this->rutaOk())]);

        $this->ors()->calcularRuta($this->origen, $this->destino);
        $this->ors()->calcularRuta($this->origen, $this->destino);

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    // Circuit breaker
    // ---------------------------------------------------------------

    public function test_tras_varias_fallas_el_circuito_se_abre_y_se_recupera_solo(): void
    {
        Http::fake(['ors.test/*' => Http::sequence()
            ->push('error', 503)->push('error', 503)->push('error', 503)
            ->push($this->rutaOk(1000, 120))]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertFallaDeMapas();
        }

        $guarda = new GuardaDeProveedor('ors');
        $this->assertTrue($guarda->circuitoAbierto());

        // Circuito abierto: no se llama al proveedor.
        $this->assertFallaDeMapas();
        Http::assertSentCount(3);

        // Pasado el tiempo, una consulta de prueba lo cierra si responde bien.
        $this->travel(61)->seconds();
        $this->assertSame(1000, $this->ors()->calcularRuta($this->origen, $this->destino)->distanciaMetros);
        $this->assertFalse($guarda->circuitoAbierto());
        $this->assertSame(0, $guarda->estado()['fallas_consecutivas']);
    }

    public function test_si_la_consulta_de_prueba_falla_el_circuito_se_reabre_de_inmediato(): void
    {
        Http::fake(['ors.test/*' => Http::response('error', 503)]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertFallaDeMapas();
        }

        $this->travel(61)->seconds();
        $this->assertFallaDeMapas();

        $this->assertTrue((new GuardaDeProveedor('ors'))->circuitoAbierto());
        Http::assertSentCount(4);
    }

    public function test_un_http_429_abre_el_circuito_inmediatamente(): void
    {
        Http::fake(['ors.test/*' => Http::response(['error' => 'Quota exceeded'], 429)]);

        $this->assertFallaDeMapas();
        $this->assertFallaDeMapas();

        Http::assertSentCount(1);
        $estado = (new GuardaDeProveedor('ors'))->estado();
        $this->assertNotNull($estado['circuito_abierto_hasta']);

        $this->travel(299)->seconds();
        $this->assertFallaDeMapas();
        Http::assertSentCount(1);
    }

    public function test_un_exito_reinicia_el_contador_de_fallas(): void
    {
        Http::fake(['ors.test/*' => Http::sequence()
            ->push('error', 503)->push('error', 503)
            ->push($this->rutaOk())
            ->push('error', 503)->push('error', 503)]);

        $this->assertFallaDeMapas();
        $this->assertFallaDeMapas();
        $this->ors()->calcularRuta($this->origen, $this->destino);
        $this->assertFallaDeMapas();
        $this->assertFallaDeMapas();

        $this->assertFalse((new GuardaDeProveedor('ors'))->circuitoAbierto());
    }

    // ---------------------------------------------------------------
    // Cotizador
    // ---------------------------------------------------------------

    public function test_sin_recorrido_por_calles_la_cotizacion_se_rechaza(): void
    {
        Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);
        ZonaTarifaria::factory()->create(['distancia_max_km' => 5, 'monto' => '2000.00']);
        Http::fake(['ors.test/*' => Http::response(['error' => ['code' => 2010]], 404)]);

        $this->expectException(CotizacionNoDisponible::class);

        (new CotizadorService($this->ors()))->cotizar($this->origen, $this->destino);
    }

    public function test_sin_recorrido_y_rechazo_deshabilitado_usa_la_contingencia(): void
    {
        config(['services.mapas.rechazar_sin_ruta' => false]);
        Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);
        ZonaTarifaria::factory()->create(['distancia_max_km' => 5, 'monto' => '2000.00']);
        Http::fake(['ors.test/*' => Http::response(['error' => ['code' => 2009]], 404)]);

        $cotizacion = (new CotizadorService($this->ors()))->cotizar($this->origen, $this->destino);

        $this->assertSame(MetodoCotizacion::Zona, $cotizacion->metodo);
    }

    public function test_con_cuota_agotada_el_cotizador_usa_la_contingencia_por_zonas(): void
    {
        config(['services.mapas.cuotas.ors.por_dia' => 1]);
        Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);
        ZonaTarifaria::factory()->create(['distancia_max_km' => 5, 'monto' => '2000.00']);
        Http::fake(['ors.test/*' => Http::response($this->rutaOk())]);
        $cotizador = new CotizadorService($this->ors());

        $this->assertSame(MetodoCotizacion::Mapa, $cotizador->cotizar($this->origen, $this->destino)->metodo);
        $contingencia = $cotizador->cotizar($this->origen, $this->destino);

        $this->assertSame(MetodoCotizacion::Zona, $contingencia->metodo);
        $this->assertSame('2000.00', $contingencia->monto);
        Http::assertSentCount(1);
    }

    public function test_cotizar_por_api_sin_recorrido_responde_422(): void
    {
        config(['services.mapas.driver' => 'ors', 'services.mapas.ors.api_key' => 'k', 'services.mapas.ors.url' => self::ORS]);
        Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);
        Http::fake(['ors.test/*' => Http::response(['error' => ['code' => 2010]], 404)]);
        $cuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cuenta)->postJson('/api/v1/viajes/cotizar', [
            'origen_lat' => $this->origen->lat, 'origen_lng' => $this->origen->lng,
            'destino_lat' => $this->destino->lat, 'destino_lng' => $this->destino->lng,
        ])->assertUnprocessable()->assertJsonValidationErrors('destino_lat');
    }

    private function assertFallaDeMapas(): void
    {
        try {
            $this->ors()->calcularRuta($this->origen, $this->destino);
            $this->fail('Se esperaba ServicioMapasNoDisponible.');
        } catch (ServicioMapasNoDisponible) {
            $this->addToAssertionCount(1);
        }
    }
}
