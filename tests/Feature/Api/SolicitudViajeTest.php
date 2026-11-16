<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Events\ViajeListoParaDespacho;
use App\Jobs\DespacharReservaJob;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Persona;
use App\Models\Tarifa;
use App\Models\Viaje;
use App\Services\Mapas\ProveedorDistancia;
use App\Services\Mapas\Ruta;
use App\ValueObjects\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CU 01 (solicitar), CU 09 (reservar) y CU 10 (registro telefónico).
 */
class SolicitudViajeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $payload = [
        'origen_lat' => -34.9214,
        'origen_lng' => -57.9544,
        'destino_lat' => -34.9055,
        'destino_lng' => -57.9325,
        'metodo_pago' => 'efectivo',
        'origen_calle' => 'Calle 7',
        'origen_numero' => '800',
        'destino_calle' => 'Calle 50',
        'destino_numero' => '1200',
    ];

    private Tarifa $tarifa;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ViajeListoParaDespacho::class]);
        $this->tarifa = Tarifa::factory()->create(['precio_base' => 1500, 'precio_por_km' => 350]);

        // Ruta de 3 km y 7 minutos: 1500 + 3 * 350 = 2550,00.
        $this->app->instance(ProveedorDistancia::class, new class implements ProveedorDistancia
        {
            public function calcularRuta(Coordinate $origen, Coordinate $destino): Ruta
            {
                return new Ruta(3000, 420, 'fake');
            }
        });
    }

    private function cliente(): Cuenta
    {
        $cuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $cuenta->persona_id]);

        return $cuenta;
    }

    private function admin(): Cuenta
    {
        return Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
    }

    public function test_solicitar_registra_el_viaje_con_la_cotizacion_del_servidor(): void
    {
        $cliente = $this->cliente();

        $id = $this->actingAs($cliente)->postJson('/api/v1/cliente/viajes/solicitar', $this->payload + [
            // Un monto enviado por el cliente nunca se usa.
            'costo' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'solicitado')
            ->assertJsonPath('data.tipo', 'actual')
            ->assertJsonPath('data.metodo_pago', 'efectivo')
            ->assertJsonPath('data.costo', '2550.00')
            ->assertJsonPath('data.metodo_cotizacion', 'mapa')
            ->json('data.id');

        $viaje = Viaje::query()->findOrFail($id);
        $this->assertSame($cliente->persona_id, $viaje->cliente_id);
        $this->assertSame($this->tarifa->id, $viaje->tarifa_id);
        $this->assertSame('1500.00', $viaje->precio_base_aplicado);
        $this->assertSame('350.00', $viaje->precio_por_km_aplicado);
        $this->assertSame(3.0, $viaje->distancia_estimada_km);
        $this->assertSame(7, $viaje->duracion_estimada_min);
        $this->assertSame('Calle 50', $viaje->destino_calle);
        $this->assertSame('app', $viaje->canal);
        $this->assertNotNull($viaje->despachado_en);

        Event::assertDispatched(ViajeListoParaDespacho::class, fn (ViajeListoParaDespacho $e): bool => $e->viajeId === $id);
    }

    public function test_un_cambio_posterior_de_tarifa_no_altera_el_viaje_cotizado(): void
    {
        $id = $this->actingAs($this->cliente())->postJson('/api/v1/cliente/viajes/solicitar', $this->payload)
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin())->putJson('/api/v1/admin/tarifas', [
            'precio_base' => 9000,
            'precio_por_km' => 900,
        ])->assertOk();

        $viaje = Viaje::query()->findOrFail($id);
        $this->assertSame('2550.00', $viaje->costo);
        $this->assertSame('1500.00', $viaje->precio_base_aplicado);
    }

    public function test_un_monto_confirmado_desactualizado_devuelve_409_con_la_cotizacion_vigente(): void
    {
        $this->actingAs($this->cliente())->postJson('/api/v1/cliente/viajes/solicitar', $this->payload + [
            'monto_confirmado' => '2000.00',
        ])
            ->assertStatus(409)
            ->assertJsonPath('cotizacion.monto', '2550.00');

        $this->assertDatabaseCount('viajes', 0);
        Event::assertNotDispatched(ViajeListoParaDespacho::class);
    }

    public function test_un_monto_confirmado_vigente_se_acepta(): void
    {
        $this->actingAs($this->cliente())->postJson('/api/v1/cliente/viajes/solicitar', $this->payload + [
            'monto_confirmado' => 2550,
        ])->assertCreated();
    }

    public function test_el_metodo_de_pago_es_obligatorio_y_debe_ser_valido(): void
    {
        $cliente = $this->cliente();
        $sinMetodo = $this->payload;
        unset($sinMetodo['metodo_pago']);

        $this->actingAs($cliente)->postJson('/api/v1/cliente/viajes/solicitar', $sinMetodo)
            ->assertUnprocessable()->assertJsonValidationErrors('metodo_pago');

        // La cuenta corriente se eliminó del dominio (commit 94682ac).
        $this->actingAs($cliente)->postJson('/api/v1/cliente/viajes/solicitar', ['metodo_pago' => 'cuentaCorriente'] + $this->payload)
            ->assertUnprocessable()->assertJsonValidationErrors('metodo_pago');
    }

    public function test_un_conductor_no_puede_solicitar_viajes(): void
    {
        $conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);

        $this->actingAs($conductor)->postJson('/api/v1/cliente/viajes/solicitar', $this->payload)->assertForbidden();
    }

    public function test_sin_tarifa_vigente_no_se_registra_el_viaje(): void
    {
        Tarifa::query()->update(['activa' => false]);
        Tarifa::olvidarVigenteEnCache();

        $this->actingAs($this->cliente())->postJson('/api/v1/cliente/viajes/solicitar', $this->payload)->assertStatus(409);
        $this->assertDatabaseCount('viajes', 0);
    }

    public function test_reservar_guarda_el_viaje_reservado_y_programa_el_despacho(): void
    {
        Queue::fake();
        config(['remiseria.ventana_despacho_programados_minutos' => 15]);
        $fecha = now()->addDay()->setTime(8, 30)->startOfMinute();

        $id = $this->actingAs($this->cliente())->postJson('/api/v1/cliente/viajes/reservar', $this->payload + [
            'fecha_viaje' => $fecha->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'reservado')
            ->assertJsonPath('data.tipo', 'programado')
            ->json('data.id');

        $viaje = Viaje::query()->findOrFail($id);
        $this->assertTrue($viaje->fecha_viaje->equalTo($fecha));
        $this->assertNull($viaje->despachado_en);

        // No se despacha de inmediato: se programa para la ventana previa.
        Event::assertNotDispatched(ViajeListoParaDespacho::class);
        Queue::assertPushed(DespacharReservaJob::class, function (DespacharReservaJob $job) use ($id, $fecha): bool {
            return $job->viajeId === $id && $job->delay->equalTo($fecha->copy()->subMinutes(15));
        });
    }

    public function test_reserva_fuera_del_rango_permitido_se_rechaza(): void
    {
        config(['remiseria.reservas.anticipacion_minima_minutos' => 30, 'remiseria.reservas.anticipacion_maxima_dias' => 30]);
        $cliente = $this->cliente();

        foreach ([now()->subHour(), now()->addMinutes(10), now()->addDays(31)] as $fecha) {
            $this->actingAs($cliente)->postJson('/api/v1/cliente/viajes/reservar', $this->payload + [
                'fecha_viaje' => $fecha->toIso8601String(),
            ])->assertUnprocessable()->assertJsonValidationErrors('fecha_viaje');
        }

        $this->assertDatabaseCount('viajes', 0);
    }

    public function test_el_endpoint_anterior_sigue_funcionando(): void
    {
        $this->actingAs($this->cliente())->postJson('/api/v1/cliente/viajes', [
            'origen_lat' => -34.9214,
            'origen_lng' => -57.9544,
            'destino_lat' => -34.9055,
            'destino_lng' => -57.9325,
        ])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'solicitado')
            ->assertJsonPath('data.metodo_pago', 'efectivo')
            ->assertJsonPath('data.costo', '2550.00');
    }

    public function test_el_endpoint_anterior_con_tipo_programado_crea_una_reserva(): void
    {
        $this->actingAs($this->cliente())->postJson('/api/v1/cliente/viajes', [
            'origen_lat' => -34.9214,
            'origen_lng' => -57.9544,
            'destino_lat' => -34.9055,
            'destino_lng' => -57.9325,
            'tipo' => 'programado',
            'fecha_viaje' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.estado', 'reservado');
    }

    public function test_admin_registra_un_viaje_de_un_cliente_existente(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::factory()->create();

        $id = $this->actingAs($admin)->postJson('/api/v1/admin/viajes/registrar', $this->payload + [
            'cliente_id' => $cliente->persona_id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'solicitado')
            ->json('data.id');

        $viaje = Viaje::query()->findOrFail($id);
        $this->assertSame($cliente->persona_id, $viaje->cliente_id);
        $this->assertSame('telefono', $viaje->canal);
        $this->assertSame($admin->persona_id, $viaje->registrado_por_persona_id);
        Event::assertDispatched(ViajeListoParaDespacho::class);
    }

    public function test_admin_registra_un_cliente_no_registrado_sin_cuenta_de_acceso(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/admin/viajes/registrar', $this->payload + [
            'cliente' => [
                'dni' => '30.123.456',
                'nombre' => 'Ana',
                'apellido' => 'Pérez',
                'telefono' => '+54 221 555-1234',
            ],
        ])->assertCreated();

        $persona = Persona::query()->where('dni', '30123456')->firstOrFail();
        $this->assertNotNull($persona->cliente);
        $this->assertNull($persona->cuenta);
        $this->assertSame(1, Viaje::query()->where('cliente_id', $persona->id)->count());
    }

    public function test_un_dni_existente_con_el_mismo_nombre_reutiliza_la_persona(): void
    {
        $persona = Persona::factory()->create(['dni' => '30123456', 'nombre' => 'José', 'apellido' => 'Gómez']);

        $this->actingAs($this->admin())->postJson('/api/v1/admin/viajes/registrar', $this->payload + [
            'cliente' => ['dni' => '30123456', 'nombre' => 'jose', 'apellido' => 'GOMEZ', 'telefono' => '2215551234'],
        ])->assertCreated();

        $this->assertSame(1, Persona::query()->where('dni', '30123456')->count());
        $this->assertSame('José', $persona->fresh()->nombre);
        $this->assertNotNull($persona->fresh()->cliente);
    }

    public function test_un_dni_existente_con_otro_nombre_se_rechaza(): void
    {
        Persona::factory()->create(['dni' => '30123456', 'nombre' => 'José', 'apellido' => 'Gómez']);

        $this->actingAs($this->admin())->postJson('/api/v1/admin/viajes/registrar', $this->payload + [
            'cliente' => ['dni' => '30123456', 'nombre' => 'María', 'apellido' => 'López', 'telefono' => '2215551234'],
        ])->assertUnprocessable()->assertJsonValidationErrors('cliente.dni');

        $this->assertDatabaseCount('viajes', 0);
    }

    public function test_admin_puede_registrar_una_reserva_telefonica(): void
    {
        Queue::fake();
        $cliente = Cliente::factory()->create();

        $this->actingAs($this->admin())->postJson('/api/v1/admin/viajes/registrar', $this->payload + [
            'cliente_id' => $cliente->persona_id,
            'fecha_viaje' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.estado', 'reservado');

        Queue::assertPushed(DespacharReservaJob::class);
    }

    public function test_registro_telefonico_exige_identificar_al_cliente(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/admin/viajes/registrar', $this->payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cliente_id', 'cliente']);
    }

    public function test_un_cliente_no_puede_registrar_viajes_telefonicos(): void
    {
        $this->actingAs($this->cliente())->postJson('/api/v1/admin/viajes/registrar', $this->payload + [
            'cliente_id' => Cliente::factory()->create()->persona_id,
        ])->assertForbidden();
    }

    public function test_el_cliente_no_puede_cancelar_un_viaje_en_curso_pero_un_admin_si(): void
    {
        $cliente = $this->cliente();
        $viaje = Viaje::factory()->create(['cliente_id' => $cliente->persona_id, 'estado' => EstadoViaje::EnCurso]);

        $this->actingAs($cliente)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar")->assertStatus(409);
        $this->assertSame(EstadoViaje::EnCurso, $viaje->fresh()->estado);

        $this->actingAs($this->admin())->patchJson("/api/v1/viajes/{$viaje->id}/cancelar")->assertOk();
        $this->assertSame(EstadoViaje::Cancelado, $viaje->fresh()->estado);
    }

    public function test_el_cliente_puede_cancelar_su_reserva(): void
    {
        $cliente = $this->cliente();
        $viaje = Viaje::factory()->create([
            'cliente_id' => $cliente->persona_id,
            'estado' => EstadoViaje::Reservado,
            'tipo' => TipoViaje::Programado,
            'fecha_viaje' => now()->addDay(),
        ]);

        $this->actingAs($cliente)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar")->assertOk();
        $this->assertSame(EstadoViaje::Cancelado, $viaje->fresh()->estado);
    }
}
