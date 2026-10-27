<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\ZonaTarifaria;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\Ruta;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizarViajeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, float> */
    private array $trayecto = [
        'origen_lat' => -34.9214,
        'origen_lng' => -57.9544,
        'destino_lat' => -34.9055,
        'destino_lng' => -57.9325,
    ];

    private function conMapa(int $metros, int $segundos): void
    {
        $this->app->instance(ProveedorDistancia::class, new class($metros, $segundos) implements ProveedorDistancia
        {
            public function __construct(private int $metros, private int $segundos) {}

            public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
            {
                return new Ruta($this->metros, $this->segundos, 'fake');
            }
        });
    }

    public function test_un_cliente_obtiene_monto_y_distancia(): void
    {
        Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);
        $this->conMapa(3000, 420);
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', $this->trayecto)
            ->assertOk()
            ->assertJsonPath('data.monto', '2550.00')
            ->assertJsonPath('data.distancia_km', 3)
            ->assertJsonPath('data.duracion_minutos', 7)
            ->assertJsonPath('data.metodo', 'mapa');
    }

    public function test_un_administrador_tambien_puede_cotizar(): void
    {
        Tarifa::factory()->create();
        $this->conMapa(3000, 420);
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)->postJson('/api/v1/viajes/cotizar', $this->trayecto)->assertOk();
    }

    public function test_un_conductor_no_puede_cotizar(): void
    {
        $conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);

        $this->actingAs($conductor)->postJson('/api/v1/viajes/cotizar', $this->trayecto)->assertForbidden();
    }

    public function test_sin_autenticacion_devuelve_401(): void
    {
        $this->postJson('/api/v1/viajes/cotizar', $this->trayecto)->assertUnauthorized();
    }

    public function test_sin_servicio_de_mapas_responde_con_la_contingencia_por_zonas(): void
    {
        // En tests MAPAS_DRIVER no está definido: driver "ninguno".
        Tarifa::factory()->create();
        ZonaTarifaria::factory()->create(['distancia_max_km' => 10, 'monto' => 2800]);
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', $this->trayecto)
            ->assertOk()
            ->assertJsonPath('data.metodo', 'zona')
            ->assertJsonPath('data.monto', '2800.00')
            ->assertJsonPath('data.duracion_minutos', null);
    }

    public function test_coordenadas_invalidas_devuelven_422(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', ['origen_lat' => 91] + $this->trayecto)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origen_lat');

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', ['destino_lng' => 'abc'] + $this->trayecto)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destino_lng');
    }

    public function test_origen_igual_al_destino_devuelve_422(): void
    {
        Tarifa::factory()->create();
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', [
            'destino_lat' => $this->trayecto['origen_lat'],
            'destino_lng' => $this->trayecto['origen_lng'],
        ] + $this->trayecto)->assertUnprocessable();
    }

    public function test_sin_tarifa_vigente_devuelve_409(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->postJson('/api/v1/viajes/cotizar', $this->trayecto)->assertStatus(409);
    }
}
