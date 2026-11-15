<?php

declare(strict_types=1);

namespace Tests\Feature\Seguridad;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Pago;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Reverb\ApplicationManager;
use Tests\TestCase;

/**
 * Regresiones de la auditoría final de seguridad (INFORME_AUDITORIA_SEGURIDAD_BACKEND.md).
 */
class AuditoriaFinalTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $cliente;

    private Cuenta $conductor;

    private Viaje $viaje;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente, 'email' => 'pasajero@example.test', 'password' => 'clave-correcta-123']);
        Cliente::factory()->create(['persona_id' => $this->cliente->persona_id]);
        $this->conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $this->conductor->persona_id, 'vehiculo_id' => Vehiculo::factory()->create()->id]);
        $this->viaje = Viaje::factory()->create([
            'cliente_id' => $this->cliente->persona_id,
            'conductor_id' => $this->conductor->persona_id,
            'estado' => EstadoViaje::EnCurso,
        ]);
    }

    // SEC-01 -------------------------------------------------------------

    public function test_sec01_el_pasajero_no_puede_suscribirse_al_canal_privado_del_conductor(): void
    {
        $canalConductor = ['channel_name' => "private-conductor.{$this->conductor->persona_id}", 'socket_id' => '1234.5678'];

        // Aun con un viaje en curso con ese conductor: ese canal transporta
        // las ofertas (direcciones de otros pasajeros) y lo escucharía para siempre.
        $this->actingAs($this->cliente, 'sanctum')->postJson('/api/v1/broadcasting/auth', $canalConductor)->assertForbidden();

        // La ubicación del viaje le llega por su canal del viaje.
        $this->actingAs($this->cliente, 'sanctum')->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => "private-viaje.{$this->viaje->id}", 'socket_id' => '1234.5678',
        ])->assertOk();

        $this->actingAs($this->conductor, 'sanctum')->postJson('/api/v1/broadcasting/auth', $canalConductor)->assertOk();

        $otroConductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        $this->actingAs($otroConductor, 'sanctum')->postJson('/api/v1/broadcasting/auth', $canalConductor)->assertForbidden();

        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/broadcasting/auth', $canalConductor)->assertOk();
    }

    // SEC-02 -------------------------------------------------------------

    public function test_sec02_reverb_no_acepta_eventos_de_cliente(): void
    {
        $app = app(ApplicationManager::class)->driver()->all()->first();

        $this->assertSame('none', $app->acceptClientEventsFrom());
    }

    // SEC-03 -------------------------------------------------------------

    public function test_sec03_pasajero_y_conductor_no_ven_el_historial_interno_del_pago(): void
    {
        $this->viaje->update(['estado' => EstadoViaje::Finalizado]);
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $pago = Pago::factory()->create([
            'viaje_id' => $this->viaje->id,
            'metodo_pago' => MetodoPago::Efectivo,
            'estado' => EstadoPago::Confirmado,
            'monto' => '1000.00',
            'observacion' => 'nota interna del administrador',
            'registrado_por_persona_id' => $this->conductor->persona_id,
            'historial' => [['accion' => 'registro', 'persona_id' => $admin->persona_id, 'mp_payment_id' => '999']],
        ]);

        foreach ([$this->cliente, $this->conductor] as $cuenta) {
            $this->actingAs($cuenta)->getJson("/api/v1/viajes/{$this->viaje->id}")
                ->assertOk()
                ->assertJsonPath('data.pago.id', $pago->id)
                ->assertJsonPath('data.pago.estado', 'confirmado')
                ->assertJsonPath('data.pago.monto', '1000.00')
                ->assertJsonMissingPath('data.pago.historial')
                ->assertJsonMissingPath('data.pago.observacion')
                ->assertJsonMissingPath('data.pago.registrado_por_persona_id');
        }

        $this->actingAs($admin)->getJson("/api/v1/viajes/{$this->viaje->id}")
            ->assertOk()
            ->assertJsonPath('data.pago.observacion', 'nota interna del administrador')
            ->assertJsonPath('data.pago.historial.0.accion', 'registro');
    }

    public function test_sec03_el_registro_de_pago_del_conductor_no_devuelve_el_historial(): void
    {
        $this->viaje->update(['estado' => EstadoViaje::Finalizado, 'costo' => '1500.00', 'costo_final' => '1500.00']);

        $this->actingAs($this->conductor)
            ->postJson("/api/v1/conductor/viajes/{$this->viaje->id}/pago", ['metodo_pago' => 'efectivo', 'monto' => '1500.00'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'confirmado')
            ->assertJsonMissingPath('data.historial')
            ->assertJsonMissingPath('data.registrado_por_persona_id');
    }

    // SEC-04 -------------------------------------------------------------

    public function test_sec04_cambiar_la_contrasena_o_el_email_revoca_los_tokens(): void
    {
        $token = $this->conductor->createToken('api-token', ['conductor'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->conductor->update(['password' => 'otra-clave-segura-456']);

        $this->assertSame(0, $this->conductor->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->conductor->createToken('api-token');
        $this->conductor->update(['email' => 'nuevo@example.test']);
        $this->assertSame(0, $this->conductor->tokens()->count());

        // Otros cambios no revocan.
        $this->conductor->createToken('api-token');
        $this->conductor->touch();
        $this->assertSame(1, $this->conductor->tokens()->count());
    }

    // SEC-05 -------------------------------------------------------------

    public function test_sec05_limite_de_intentos_fallidos_por_cuenta_aunque_cambie_la_ip(): void
    {
        config(['remiseria.abuso.login_intentos_por_cuenta' => 3]);

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/login', ['email' => 'PASAJERO@example.test', 'password' => 'mala'])
                ->assertUnauthorized();
        }

        // Desde otra IP y aun con la contraseña correcta: bloqueado.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])
            ->postJson('/api/v1/auth/login', ['email' => 'pasajero@example.test', 'password' => 'clave-correcta-123'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // Otra cuenta no se ve afectada.
        $otra = Cuenta::factory()->create(['rol' => RolPersona::Cliente, 'email' => 'otra@example.test', 'password' => 'clave-correcta-123']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/v1/auth/login', ['email' => $otra->email, 'password' => 'clave-correcta-123'])
            ->assertOk();

        // Un email inexistente se limita igual (no permite enumerar cuentas).
        foreach (['10.0.1.1', '10.0.1.2', '10.0.1.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/login', ['email' => 'nadie@example.test', 'password' => 'x'])
                ->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.4'])
            ->postJson('/api/v1/auth/login', ['email' => 'nadie@example.test', 'password' => 'x'])
            ->assertStatus(429);

        // Al vencer la ventana, vuelve a funcionar.
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])
            ->postJson('/api/v1/auth/login', ['email' => 'pasajero@example.test', 'password' => 'clave-correcta-123'])
            ->assertOk();
    }

    public function test_sec05_un_login_correcto_reinicia_el_contador(): void
    {
        config(['remiseria.abuso.login_intentos_por_cuenta' => 3]);

        foreach ([1, 2] as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.2.{$i}"])
                ->postJson('/api/v1/auth/login', ['email' => 'pasajero@example.test', 'password' => 'mala'])->assertUnauthorized();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.3'])
            ->postJson('/api/v1/auth/login', ['email' => 'pasajero@example.test', 'password' => 'clave-correcta-123'])->assertOk();

        foreach ([4, 5] as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.2.{$i}"])
                ->postJson('/api/v1/auth/login', ['email' => 'pasajero@example.test', 'password' => 'mala'])->assertUnauthorized();
        }
    }

    // SEC-06 -------------------------------------------------------------

    public function test_sec06_limite_por_hora_para_vincular_tarjetas(): void
    {
        config([
            'services.mercadopago.access_token' => 'TEST-token',
            'services.mercadopago.base_url' => 'https://api.mercadopago.test',
            'remiseria.abuso.vincular_tarjetas_por_hora' => 3,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.mercadopago.test/v1/customers/search*' => Http::response(['results' => [['id' => 'cus-1']]]),
            'https://api.mercadopago.test/v1/customers/cus-1/cards' => Http::response(['message' => 'card rejected'], 400),
        ]);
        $datos = ['token' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'payment_method_id' => 'visa', 'tipo' => 'credit_card', 'acepto_guardar_tarjeta' => true];

        // Tarjetas rechazadas (card testing): cada intento cuenta.
        foreach ([1, 2, 3] as $_) {
            $this->actingAs($this->cliente)->postJson('/api/v1/cliente/medios-pago', $datos)->assertUnprocessable();
        }

        $this->actingAs($this->cliente)->postJson('/api/v1/cliente/medios-pago', $datos)
            ->assertStatus(429)->assertHeader('Retry-After');

        // 1 búsqueda del customer (después queda guardado) + 3 intentos de
        // guardar la tarjeta; el 4.º intento no llega a Mercado Pago.
        Http::assertSentCount(4);

        $this->travel(61)->minutes();
        $this->actingAs($this->cliente)->postJson('/api/v1/cliente/medios-pago', $datos)->assertUnprocessable();
    }

    // SEC-07 -------------------------------------------------------------

    public function test_sec07_solo_se_aceptan_el_host_exacto_de_app_url_y_los_configurados(): void
    {
        config(['app.url' => 'https://api.remiseria.test', 'app.hosts_de_confianza' => 'nginx, ']);

        $patrones = app(TrustHosts::class)->hosts();
        $acepta = fn (string $host): bool => collect($patrones)->contains(fn (string $p): bool => (bool) preg_match('{'.$p.'}i', $host));

        $this->assertTrue($acepta('api.remiseria.test'));
        $this->assertTrue($acepta('nginx'));
        $this->assertFalse($acepta('evil.test'));
        $this->assertFalse($acepta('x.api.remiseria.test'));
        $this->assertFalse($acepta('api.remiseria.test.evil.test'));
    }

    // SEC-08 -------------------------------------------------------------

    public function test_sec08_la_documentacion_de_la_api_se_deshabilita_en_produccion(): void
    {
        config(['remiseria.documentacion_api_publica' => false]);
        $this->get('/api/documentation')->assertNotFound();
        $this->get('/docs')->assertNotFound();

        config(['remiseria.documentacion_api_publica' => true]);
        $this->get('/api/documentation')->assertOk();
    }

    // SEC-16 -------------------------------------------------------------

    public function test_sec16_sin_token_y_sin_accept_json_la_api_responde_401_no_500(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->get('/api/v1/admin/pagos', ['Accept' => 'text/html'])->assertUnauthorized();
        $this->post('/api/v1/cliente/medios-pago')->assertUnauthorized();
    }

    // SEC-11 -------------------------------------------------------------

    public function test_sec11_los_errores_de_difusion_no_registran_la_firma_de_reverb(): void
    {
        Log::spy();

        report(new BroadcastException('Pusher error: cURL error 7: Failed to connect for http://reverb:8080/apps/1/events?auth_key=CLAVE&auth_timestamp=1&auth_version=1.0&body_md5=x&auth_signature=FIRMA.'));

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $mensaje, array $contexto): bool {
            return str_contains($contexto['error'], 'http://reverb:8080/apps/1/events?<oculto>')
                && ! str_contains($contexto['error'], 'FIRMA')
                && ! str_contains($contexto['error'], 'CLAVE');
        });
    }
}
