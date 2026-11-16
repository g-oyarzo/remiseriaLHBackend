<?php

declare(strict_types=1);

namespace Tests\Feature\Cotizacion;

use App\Services\Mapas\GoogleRoutesProveedorDistancia;
use App\Services\Mapas\OsrmProveedorDistancia;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\ServicioMapasNoDisponible;
use App\Services\Mapas\SinProveedorDistancia;
use App\ValueObjects\Coordinate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProveedoresDeMapasTest extends TestCase
{
    private Coordinate $origen;

    private Coordinate $destino;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mapas.cache_minutos' => 0, 'services.mapas.reintentos' => 0]);
        $this->origen = new Coordinate(lat: -34.9214, lng: -57.9544);
        $this->destino = new Coordinate(lat: -34.9055, lng: -57.9325);
    }

    public function test_osrm_devuelve_distancia_y_duracion(): void
    {
        Http::fake(['osrm.test/*' => Http::response([
            'code' => 'Ok',
            'routes' => [['distance' => 3456.7, 'duration' => 512.4]],
        ])]);

        $ruta = (new OsrmProveedorDistancia('https://osrm.test'))->calcularRuta($this->origen, $this->destino);

        $this->assertSame(3457, $ruta->distanciaMetros);
        $this->assertSame(512, $ruta->duracionSegundos);
        $this->assertSame(3.457, $ruta->distanciaKm());
        $this->assertSame(9, $ruta->duracionMinutos());

        // OSRM espera longitud,latitud.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/route/v1/driving/-57.954400,-34.921400;-57.932500,-34.905500'));
    }

    public function test_osrm_sin_ruta_falla(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['code' => 'NoRoute', 'routes' => []])]);

        $this->expectException(ServicioMapasNoDisponible::class);

        (new OsrmProveedorDistancia('https://osrm.test'))->calcularRuta($this->origen, $this->destino);
    }

    public function test_error_http_del_proveedor_falla(): void
    {
        Http::fake(['osrm.test/*' => Http::response('error', 503)]);

        $this->expectException(ServicioMapasNoDisponible::class);

        (new OsrmProveedorDistancia('https://osrm.test'))->calcularRuta($this->origen, $this->destino);
    }

    public function test_un_timeout_falla_sin_filtrar_la_url_ni_credenciales(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://maps.test/?key=SECRETO'));

        try {
            (new GoogleRoutesProveedorDistancia('SECRETO', 'https://maps.test/compute'))->calcularRuta($this->origen, $this->destino);
            $this->fail('Se esperaba ServicioMapasNoDisponible.');
        } catch (ServicioMapasNoDisponible $e) {
            $this->assertStringNotContainsString('SECRETO', $e->getMessage());
            $this->assertStringNotContainsString('https://', $e->getMessage());
        }
    }

    public function test_reintenta_ante_una_falla_de_conexion_transitoria(): void
    {
        config(['services.mapas.reintentos' => 1]);
        Http::fake(['osrm.test/*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['code' => 'Ok', 'routes' => [['distance' => 1000, 'duration' => 120]]])]);

        $ruta = (new OsrmProveedorDistancia('https://osrm.test'))->calcularRuta($this->origen, $this->destino);

        $this->assertSame(1000, $ruta->distanciaMetros);
        Http::assertSentCount(2);
    }

    public function test_valores_absurdos_del_proveedor_se_rechazan(): void
    {
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => -5, 'duration' => 10]]])]);

        $this->expectException(ServicioMapasNoDisponible::class);

        (new OsrmProveedorDistancia('https://osrm.test'))->calcularRuta($this->origen, $this->destino);
    }

    public function test_google_routes_envia_la_clave_en_un_header_y_parsea_la_duracion(): void
    {
        Http::fake(['maps.test/*' => Http::response([
            'routes' => [['distanceMeters' => 4321, 'duration' => '754s']],
        ])]);

        $ruta = (new GoogleRoutesProveedorDistancia('CLAVE', 'https://maps.test/compute'))->calcularRuta($this->origen, $this->destino);

        $this->assertSame(4321, $ruta->distanciaMetros);
        $this->assertSame(754, $ruta->duracionSegundos);
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Goog-Api-Key', 'CLAVE')
            && ! str_contains($r->url(), 'CLAVE')
            && $r['origin']['location']['latLng']['latitude'] === -34.9214);
    }

    public function test_google_sin_rutas_falla(): void
    {
        Http::fake(['maps.test/*' => Http::response([])]);

        $this->expectException(ServicioMapasNoDisponible::class);

        (new GoogleRoutesProveedorDistancia('CLAVE', 'https://maps.test/compute'))->calcularRuta($this->origen, $this->destino);
    }

    public function test_las_rutas_se_cachean_por_par_de_coordenadas(): void
    {
        config(['services.mapas.cache_minutos' => 5]);
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 1000, 'duration' => 60]]])]);
        $proveedor = new OsrmProveedorDistancia('https://osrm.test');

        $proveedor->calcularRuta($this->origen, $this->destino);
        $proveedor->calcularRuta($this->origen, $this->destino);

        Http::assertSentCount(1);
    }

    public function test_el_driver_por_defecto_no_envia_coordenadas_a_terceros(): void
    {
        config(['services.mapas.driver' => 'ninguno']);
        Http::fake();

        $proveedor = app(ProveedorDistancia::class);

        $this->assertInstanceOf(SinProveedorDistancia::class, $proveedor);
        $this->expectException(ServicioMapasNoDisponible::class);

        try {
            $proveedor->calcularRuta($this->origen, $this->destino);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_un_driver_mal_configurado_degrada_a_la_contingencia(): void
    {
        config(['services.mapas.driver' => 'google', 'services.mapas.google.api_key' => null]);

        $this->assertInstanceOf(SinProveedorDistancia::class, app(ProveedorDistancia::class));
    }

    public function test_el_driver_osrm_se_resuelve_con_su_url(): void
    {
        config(['services.mapas.driver' => 'osrm', 'services.mapas.osrm.url' => 'https://osrm.test']);

        $this->assertInstanceOf(OsrmProveedorDistancia::class, app(ProveedorDistancia::class));
    }
}
