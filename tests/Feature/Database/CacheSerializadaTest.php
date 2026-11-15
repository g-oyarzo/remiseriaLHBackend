<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Services\Mapas\OsrmProveedorDistancia;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresión: con un store que serializa (Redis, base de datos, archivo) y
 * cache.serializable_classes = false (valor por defecto de Laravel 13), los
 * objetos guardados en cache vuelven como __PHP_Incomplete_Class. La suite
 * usa el store "array", que no serializa, y no lo detectaba: la cotización
 * fallaba con 500 en producción. Se detectó en la prueba de carga local.
 */
class CacheSerializadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'file', 'cache.serializable_classes' => false]);
        Cache::store('file')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('file')->flush();

        parent::tearDown();
    }

    public function test_la_tarifa_vigente_sobrevive_a_la_cache_serializada(): void
    {
        $tarifa = Tarifa::factory()->create(['precio_base' => '1500.00', 'precio_por_km' => '350.00']);

        $primera = Tarifa::vigente();
        $desdeCache = Tarifa::vigente();

        $this->assertInstanceOf(Tarifa::class, $desdeCache);
        $this->assertSame($tarifa->id, $desdeCache->id);
        $this->assertSame('1500.00', (string) $desdeCache->precio_base);
        $this->assertTrue($desdeCache->exists);
        $this->assertSame($primera->id, $desdeCache->id);
    }

    public function test_una_entrada_ilegible_en_la_cache_no_deja_sin_tarifa(): void
    {
        $tarifa = Tarifa::factory()->create(['precio_base' => '1500.00', 'precio_por_km' => '350.00']);
        // Simula una entrada de otro formato u otra versión del código: un
        // objeto, que con serializable_classes=false vuelve incompleto.
        Cache::put('tarifa:vigente:datos:v0', $tarifa, 600);

        $this->assertSame($tarifa->id, Tarifa::vigente()?->id);
        $this->assertSame($tarifa->id, Tarifa::vigente()?->id);
    }

    public function test_sin_tarifa_vigente_devuelve_null(): void
    {
        $this->assertNull(Tarifa::vigente());
        $this->assertNull(Tarifa::vigente());
    }

    public function test_la_ruta_cacheada_sobrevive_a_la_cache_serializada(): void
    {
        config(['services.mapas.cache_minutos' => 10, 'services.mapas.cache_store' => 'file']);
        Http::fake(['osrm.test/*' => Http::response(['code' => 'Ok', 'routes' => [['distance' => 1234, 'duration' => 300]]])]);
        $proveedor = new OsrmProveedorDistancia('https://osrm.test');
        $a = new Coordinate(-34.9214, -57.9544);
        $b = new Coordinate(-34.9055, -57.9325);

        $proveedor->calcularRuta($a, $b);
        $ruta = $proveedor->calcularRuta($a, $b);

        $this->assertSame(1234, $ruta->distanciaMetros);
        $this->assertSame('osrm', $ruta->proveedor);
        Http::assertSentCount(1);
    }

    public function test_la_cotizacion_por_api_funciona_con_cache_serializada(): void
    {
        Tarifa::factory()->create(['precio_base' => '1500.00', 'precio_por_km' => '350.00']);
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        $datos = ['origen_lat' => -34.9214, 'origen_lng' => -57.9544, 'destino_lat' => -34.9055, 'destino_lng' => -57.9325];

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', $datos)->assertOk();
        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', $datos)->assertOk();
    }
}
