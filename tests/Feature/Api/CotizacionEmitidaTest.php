<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\CotizacionEmitida;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\Ruta;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Precio cerrado: la cotización emitida fija el monto que se cobra al
 * solicitar o reservar con su cotizacion_id.
 */
class CotizacionEmitidaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, float> */
    private array $trayecto = [
        'origen_lat' => -34.9214,
        'origen_lng' => -57.9544,
        'destino_lat' => -34.9055,
        'destino_lng' => -57.9325,
    ];

    private Cuenta $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        config(['remiseria.cotizacion.vigencia_minutos' => 5]);
        Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);
        $this->app->instance(ProveedorDistancia::class, new class implements ProveedorDistancia
        {
            public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
            {
                return new Ruta(3000, 420, 'fake');
            }
        });

        $this->cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $this->cliente->persona_id]);
    }

    private function cotizar(?Cuenta $cuenta = null, array $extra = []): string
    {
        return $this->actingAs($cuenta ?? $this->cliente)
            ->postJson('/api/v1/viajes/cotizar', $this->trayecto + $extra)
            ->assertOk()
            ->json('data.cotizacion_id');
    }

    private function solicitar(string $cotizacionId, array $extra = [], string $ruta = '/api/v1/cliente/viajes/solicitar'): TestResponse
    {
        return $this->actingAs($this->cliente)->postJson($ruta, $extra + $this->trayecto + [
            'metodo_pago' => 'efectivo',
            'cotizacion_id' => $cotizacionId,
        ]);
    }

    private function cambiarTarifa(): void
    {
        Tarifa::query()->update(['activa' => false]);
        Tarifa::factory()->create(['precio_base' => 9000, 'precio_por_km' => 900]);
        Tarifa::olvidarVigenteEnCache();
    }

    public function test_cotizar_emite_una_cotizacion_con_vigencia(): void
    {
        $this->freezeSecond();

        $this->actingAs($this->cliente)->postJson('/api/v1/viajes/cotizar', $this->trayecto)
            ->assertOk()
            ->assertJsonPath('data.monto', '2550.00')
            ->assertJsonPath('data.vigente_hasta', now()->addMinutes(5)->toIso8601String());

        $emitida = CotizacionEmitida::query()->sole();
        $this->assertSame($this->cliente->id, $emitida->cuenta_id);
        $this->assertSame('2550.00', $emitida->monto);
    }

    public function test_solicitar_con_la_cotizacion_cobra_su_monto_aunque_cambie_la_tarifa(): void
    {
        $id = $this->cotizar();
        $this->cambiarTarifa();

        $viajeId = $this->solicitar($id)
            ->assertCreated()
            ->assertJsonPath('data.costo', '2550.00')
            ->assertJsonPath('data.precio_base_aplicado', '1500.00')
            ->assertJsonPath('data.metodo_cotizacion', 'mapa')
            ->json('data.id');

        $emitida = CotizacionEmitida::query()->findOrFail($id);
        $this->assertNotNull($emitida->usada_en);
        $this->assertSame($viajeId, $emitida->viaje_id);
    }

    public function test_sin_cotizacion_se_cotiza_en_el_momento(): void
    {
        $this->cotizar();
        $this->cambiarTarifa();

        $this->actingAs($this->cliente)->postJson('/api/v1/cliente/viajes/solicitar', $this->trayecto + ['metodo_pago' => 'efectivo'])
            ->assertCreated()
            ->assertJsonPath('data.costo', '11700.00');
    }

    public function test_una_cotizacion_se_usa_una_sola_vez(): void
    {
        $id = $this->cotizar();

        $this->solicitar($id)->assertCreated();
        $this->solicitar($id)->assertStatus(409)->assertJsonValidationErrors('cotizacion_id');

        $this->assertSame(1, Viaje::query()->count());
    }

    public function test_una_cotizacion_vencida_no_se_puede_usar(): void
    {
        $id = $this->cotizar();
        $this->travel(6)->minutes();

        $this->solicitar($id)->assertStatus(409);
        $this->assertDatabaseCount('viajes', 0);
    }

    public function test_no_se_puede_usar_la_cotizacion_de_otra_cuenta(): void
    {
        $otro = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        $id = $this->cotizar($otro);

        $this->solicitar($id)->assertUnprocessable()->assertJsonValidationErrors('cotizacion_id');
        $this->assertNull(CotizacionEmitida::query()->findOrFail($id)->usada_en);
    }

    public function test_el_trayecto_debe_coincidir_con_el_cotizado(): void
    {
        $id = $this->cotizar();

        $this->solicitar($id, ['destino_lat' => -34.8800])->assertUnprocessable()->assertJsonValidationErrors('cotizacion_id');
    }

    public function test_una_cotizacion_de_reserva_solo_sirve_para_reservar(): void
    {
        $id = $this->cotizar(extra: ['reserva' => true]);

        $this->solicitar($id)->assertUnprocessable();
        $this->solicitar($id, ['fecha_viaje' => now()->addDay()->toIso8601String()], '/api/v1/cliente/viajes/reservar')
            ->assertCreated()
            ->assertJsonPath('data.estado', 'reservado');
    }

    public function test_cotizacion_id_y_monto_confirmado_son_excluyentes(): void
    {
        $id = $this->cotizar();

        $this->solicitar($id, ['monto_confirmado' => 2550])->assertUnprocessable()->assertJsonValidationErrors('cotizacion_id');
    }

    public function test_el_admin_registra_un_viaje_telefonico_con_su_cotizacion(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $id = $this->cotizar($admin);
        $this->cambiarTarifa();

        $this->actingAs($admin)->postJson('/api/v1/admin/viajes/registrar', $this->trayecto + [
            'metodo_pago' => 'efectivo',
            'cotizacion_id' => $id,
            'cliente_id' => Cliente::factory()->create()->persona_id,
        ])->assertCreated()->assertJsonPath('data.costo', '2550.00');
    }

    public function test_las_cotizaciones_vencidas_se_eliminan_despues_de_una_semana(): void
    {
        $this->cotizar();
        $this->travel(8)->days();
        $this->cotizar();

        $this->artisan('model:prune', ['--model' => [CotizacionEmitida::class]])->assertSuccessful();

        $this->assertSame(1, CotizacionEmitida::query()->count());
    }
}
