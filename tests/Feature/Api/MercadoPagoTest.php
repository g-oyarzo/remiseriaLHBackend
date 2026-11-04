<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Events\PagoActualizado;
use App\Models\Cliente;
use App\Models\Comprobante;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Pago;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Services\Pagos\MercadoPago\FirmaWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as FabricaHttp;
use Illuminate\Http\Client\Request as SolicitudHttp;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cobros con Mercado Pago: generación del enlace, webhook firmado que
 * verifica el pago contra la API, idempotencia, anomalías y conciliación.
 */
class MercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-prueba';

    private const API = 'https://api.mercadopago.test';

    private Cuenta $cliente;

    private Cuenta $conductor;

    private Viaje $viaje;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.access_token' => 'TEST-token-de-prueba',
            'services.mercadopago.webhook_secret' => self::SECRETO,
            'services.mercadopago.notification_url' => 'https://remiseria.test/api/v1/webhooks/mercadopago',
            'services.mercadopago.base_url' => self::API,
            'services.mercadopago.sandbox' => true,
        ]);

        $this->cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $this->cliente->persona_id]);
        $this->conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $this->conductor->persona_id,
            'vehiculo_id' => Vehiculo::factory()->create()->id,
        ]);

        $this->viaje = Viaje::factory()->create([
            'cliente_id' => $this->cliente->persona_id,
            'conductor_id' => $this->conductor->persona_id,
            'estado' => EstadoViaje::Finalizado,
            'costo' => '3250.50',
            'costo_final' => '3250.50',
        ]);
    }

    // ---------------------------------------------------------------
    // Generación del cobro
    // ---------------------------------------------------------------

    public function test_genera_el_enlace_de_pago_por_el_importe_exacto_del_viaje(): void
    {
        $this->fakePreferencia();

        $respuesta = $this->iniciar($this->cliente)
            ->assertCreated()
            ->assertJsonPath('data.monto', '3250.50')
            ->assertJsonPath('data.moneda', 'ARS')
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.enlace_pago', 'https://sandbox.mercadopago.test/checkout?pref_id=pref-1');

        $pago = Pago::query()->sole();
        $this->assertSame('mercadopago', $pago->proveedor);
        $this->assertSame('pref-1', $pago->preferencia_id);
        $this->assertSame(EstadoPago::Pendiente, $pago->estado);
        $this->assertSame($pago->id, $respuesta->json('data.pago_id'));

        Http::assertSent(function (SolicitudHttp $solicitud) use ($pago): bool {
            return $solicitud->url() === self::API.'/checkout/preferences'
                && $solicitud->hasHeader('Authorization', 'Bearer TEST-token-de-prueba')
                && $solicitud->hasHeader('X-Idempotency-Key')
                && $solicitud['external_reference'] === "viaje-{$this->viaje->id}-pago-{$pago->id}"
                && $solicitud['items'][0]['unit_price'] === 3250.5
                && $solicitud['items'][0]['currency_id'] === 'ARS'
                && $solicitud['binary_mode'] === true
                && str_ends_with($solicitud['notification_url'], '/api/v1/webhooks/mercadopago?source_news=webhooks')
                && ! array_key_exists('payer', $solicitud->data());
        });
    }

    public function test_reutiliza_el_enlace_vigente_y_genera_otro_si_vencio(): void
    {
        $this->fakePreferencia();

        $this->iniciar($this->conductor)->assertCreated();
        $this->iniciar($this->cliente)->assertOk()->assertJsonPath('data.enlace_pago', 'https://sandbox.mercadopago.test/checkout?pref_id=pref-1');
        Http::assertSentCount(1);

        $this->travel(31)->minutes();

        $this->iniciar($this->cliente)->assertCreated();
        Http::assertSentCount(2);
        $this->assertSame(1, Pago::query()->count());
    }

    public function test_solo_participantes_del_viaje_pueden_generar_el_cobro(): void
    {
        $this->fakePreferencia();
        $otroCliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        $otroConductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);

        $this->postJson("/api/v1/viajes/{$this->viaje->id}/pago/mercadopago")->assertUnauthorized();
        $this->iniciar($otroCliente)->assertForbidden();
        $this->iniciar($otroConductor)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, Pago::query()->count());
    }

    public function test_no_genera_cobro_para_viajes_no_finalizados_o_ya_cobrados(): void
    {
        $this->fakePreferencia();
        $enCurso = Viaje::factory()->create([
            'cliente_id' => $this->cliente->persona_id,
            'conductor_id' => $this->conductor->persona_id,
            'estado' => EstadoViaje::EnCurso,
        ]);
        $this->iniciar($this->cliente, $enCurso)->assertConflict();

        $this->actingAs($this->conductor)
            ->postJson("/api/v1/conductor/viajes/{$this->viaje->id}/pago", ['metodo_pago' => 'efectivo', 'monto' => '3250.50'])
            ->assertCreated();
        $this->iniciar($this->cliente)->assertConflict();

        Http::assertNothingSent();
    }

    public function test_sin_credenciales_responde_503(): void
    {
        config(['services.mercadopago.access_token' => null]);
        Http::fake();

        $this->iniciar($this->cliente)->assertStatus(503);

        Http::assertNothingSent();
        $this->assertSame(0, Pago::query()->count());
    }

    public function test_si_mercado_pago_falla_responde_503_y_se_puede_reintentar(): void
    {
        Http::fakeSequence(self::API.'/checkout/preferences')
            ->push(['message' => 'error'], 500)
            ->pushFailedConnection()
            ->push($this->preferencia('pref-2'), 201);

        $this->iniciar($this->cliente)->assertStatus(503);
        $this->iniciar($this->cliente)->assertStatus(503);
        $this->assertNull(Pago::query()->sole()->preferencia_id);

        $this->iniciar($this->cliente)->assertCreated();
        $this->assertSame('pref-2', Pago::query()->sole()->preferencia_id);
    }

    // ---------------------------------------------------------------
    // Webhook
    // ---------------------------------------------------------------

    public function test_el_webhook_firmado_confirma_el_pago_verificandolo_con_la_api(): void
    {
        Event::fake([PagoActualizado::class]);
        $pago = $this->pagoPendiente();
        $this->fakePago($this->datosPago($pago, ['payment_type_id' => 'credit_card']));

        $this->webhook('987654321')->assertOk();

        $pago->refresh();
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
        $this->assertSame(MetodoPago::TarjetaCredito, $pago->metodo_pago);
        $this->assertSame('987654321', $pago->referencia_externa);
        $this->assertNotNull($pago->fecha_pago);
        $this->assertSame('confirmado_por_mercadopago', collect($pago->historial)->last()['accion']);
        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/payments/987654321');

        // El comprobante interno se emite al confirmarse.
        $this->assertSame($pago->id, Comprobante::query()->sole()->pago_id);
        Event::assertDispatched(PagoActualizado::class, fn (PagoActualizado $e): bool => $e->viajeId === $this->viaje->id && $e->estado === EstadoPago::Confirmado);
    }

    public function test_el_webhook_con_firma_invalida_o_sin_secreto_se_rechaza(): void
    {
        $pago = $this->pagoPendiente();
        $this->fakePago($this->datosPago($pago));

        $this->webhook('987654321', firma: 'ts=1,v1='.str_repeat('0', 64))->assertUnauthorized();
        $this->postJson('/api/v1/webhooks/mercadopago?type=payment&data.id=987654321', [])->assertUnauthorized();

        // Firma válida para otro data.id: no sirve.
        $firmaAjena = $this->firma('111', 'req-1');
        $this->webhook('987654321', firma: $firmaAjena)->assertUnauthorized();

        config(['services.mercadopago.webhook_secret' => null]);
        $this->webhook('987654321')->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(EstadoPago::Pendiente, $pago->refresh()->estado);
    }

    public function test_el_webhook_es_idempotente(): void
    {
        $pago = $this->pagoPendiente();
        $this->fakePago($this->datosPago($pago));

        $this->webhook('987654321')->assertOk();
        $historial = $pago->refresh()->historial;

        $this->webhook('987654321')->assertOk();
        $this->webhook('987654321')->assertOk();

        $this->assertSame($historial, $pago->refresh()->historial);
        $this->assertSame(1, Comprobante::query()->count());
    }

    public function test_no_confirma_si_el_importe_o_la_moneda_no_coinciden(): void
    {
        $pago = $this->pagoPendiente();
        $this->fakePago($this->datosPago($pago, ['transaction_amount' => 3250.49]));

        $this->webhook('987654321')->assertOk();

        $pago->refresh();
        $this->assertSame(EstadoPago::Pendiente, $pago->estado);
        $this->assertSame('mercadopago_importe_no_coincide', collect($pago->historial)->last()['accion']);

        $otro = $this->pagoPendiente($this->otroViajeFinalizado());
        $this->fakePago($this->datosPago($otro, ['id' => 555, 'currency_id' => 'USD']));
        $this->webhook('555')->assertOk();
        $this->assertSame(EstadoPago::Pendiente, $otro->refresh()->estado);
        $this->assertSame(0, Comprobante::query()->count());
    }

    public function test_un_pago_rechazado_queda_pendiente_para_reintentar(): void
    {
        $pago = $this->pagoPendiente();
        $this->fakePago($this->datosPago($pago, ['status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']));

        $this->webhook('987654321')->assertOk();

        $pago->refresh();
        $this->assertSame(EstadoPago::Pendiente, $pago->estado);
        $ultimo = collect($pago->historial)->last();
        $this->assertSame('novedad_mercadopago', $ultimo['accion']);
        $this->assertSame('cc_rejected_insufficient_amount', $ultimo['mp_status_detail']);
    }

    public function test_un_reintegro_marca_el_pago_como_reintegrado(): void
    {
        $pago = $this->pagoPendiente();
        Http::fakeSequence(self::API.'/v1/payments/987654321')
            ->push($this->datosPago($pago))
            ->push($this->datosPago($pago, ['status' => 'refunded']));

        $this->webhook('987654321')->assertOk();
        $this->webhook('987654321')->assertOk();

        $this->assertSame(EstadoPago::Reintegrado, $pago->refresh()->estado);

        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $this->actingAs($admin)->getJson('/api/v1/admin/reportes/estadisticas')
            ->assertOk()
            ->assertJsonPath('data.facturacion', '0.00')
            ->assertJsonPath('data.reintegros', '3250.50');
    }

    public function test_un_pago_aprobado_para_un_viaje_ya_cobrado_en_efectivo_no_se_aplica(): void
    {
        $pago = $this->pagoPendiente();

        // El pasajero terminó pagando en efectivo: reemplaza el cobro de MP.
        $this->actingAs($this->conductor)
            ->postJson("/api/v1/conductor/viajes/{$this->viaje->id}/pago", ['metodo_pago' => 'efectivo', 'monto' => '3250.50'])
            ->assertCreated();
        $pago->refresh();
        $this->assertNull($pago->proveedor);
        $this->assertSame('reemplazo_de_cobro_mercadopago', collect($pago->historial)->last()['accion']);

        // Luego Mercado Pago aprueba el enlace viejo: se registra para reintegrar.
        $this->fakePago($this->datosPago($pago));
        $this->webhook('987654321')->assertOk();

        $pago->refresh();
        $this->assertSame(MetodoPago::Efectivo, $pago->metodo_pago);
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
        $this->assertSame('mercadopago_aprobado_sin_aplicar', collect($pago->historial)->last()['accion']);
    }

    public function test_el_webhook_ignora_otros_temas_y_referencias_ajenas(): void
    {
        Http::fake([self::API.'/v1/payments/*' => Http::response(['id' => 1, 'status' => 'approved', 'external_reference' => 'otra-cosa'])]);

        $this->webhook('123', tipo: 'merchant_order')->assertOk();
        Http::assertNothingSent();

        $this->webhook('1')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_si_no_puede_consultar_a_mercado_pago_responde_503_para_que_reintente(): void
    {
        $pago = $this->pagoPendiente();
        Http::fake([self::API.'/v1/payments/*' => fn () => throw new ConnectionException('timeout')]);

        $this->webhook('987654321')->assertStatus(503);

        $this->assertSame(EstadoPago::Pendiente, $pago->refresh()->estado);
    }

    public function test_el_administrador_no_concilia_a_mano_un_cobro_de_mercado_pago(): void
    {
        $pago = $this->pagoPendiente();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/pagos/{$pago->id}/conciliar", ['estado' => 'confirmado'])
            ->assertConflict();

        $this->assertSame(EstadoPago::Pendiente, $pago->refresh()->estado);
    }

    // ---------------------------------------------------------------
    // Conciliación periódica
    // ---------------------------------------------------------------

    public function test_el_comando_concilia_los_cobros_pendientes_contra_la_api(): void
    {
        $pago = $this->pagoPendiente();
        Http::fake([self::API.'/v1/payments/search*' => Http::response(['results' => [
            $this->datosPago($pago, ['id' => 111, 'status' => 'rejected']),
            $this->datosPago($pago, ['id' => 222]),
        ]])]);

        $this->artisan('pagos:conciliar-mercadopago')->assertSuccessful();

        $pago->refresh();
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
        $this->assertSame('222', $pago->referencia_externa);
        Http::assertSent(fn (SolicitudHttp $s): bool => str_contains($s->url(), 'external_reference=viaje-'.$this->viaje->id.'-pago-'.$pago->id));

        // Segunda corrida: ya no hay pendientes.
        $this->artisan('pagos:conciliar-mercadopago')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_el_comando_no_hace_nada_sin_credenciales(): void
    {
        $this->pagoPendiente();
        config(['services.mercadopago.access_token' => null]);
        Http::fake();

        $this->artisan('pagos:conciliar-mercadopago')->assertSuccessful();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Firma
    // ---------------------------------------------------------------

    public function test_manifiesto_de_la_firma(): void
    {
        $this->assertSame('id:abc123;request-id:r-1;ts:170;', FirmaWebhook::manifiesto('ABC123', 'r-1', '170'));
        $this->assertSame('id:123;ts:170;', FirmaWebhook::manifiesto('123', null, '170'));
        $this->assertSame('ts:170;', FirmaWebhook::manifiesto(null, '', '170'));

        $v1 = hash_hmac('sha256', 'id:123;request-id:r-1;ts:170;', 's');
        $this->assertTrue(FirmaWebhook::esValida("ts=170,v1={$v1}", 'r-1', '123', 's'));
        $this->assertTrue(FirmaWebhook::esValida('ts=170, v1='.strtoupper($v1), 'r-1', '123', 's'));
        $this->assertFalse(FirmaWebhook::esValida("ts=171,v1={$v1}", 'r-1', '123', 's'));
        $this->assertFalse(FirmaWebhook::esValida("v1={$v1}", 'r-1', '123', 's'));
        $this->assertFalse(FirmaWebhook::esValida("ts=170,v1={$v1}", 'r-1', '123', null));
    }

    // ---------------------------------------------------------------

    private function iniciar(Cuenta $como, ?Viaje $viaje = null): TestResponse
    {
        $viaje ??= $this->viaje;

        return $this->actingAs($como)->postJson("/api/v1/viajes/{$viaje->id}/pago/mercadopago");
    }

    private function webhook(string $dataId, ?string $firma = null, string $tipo = 'payment'): TestResponse
    {
        $requestId = 'req-'.$dataId;

        return $this->postJson(
            "/api/v1/webhooks/mercadopago?type={$tipo}&data.id={$dataId}",
            ['action' => 'payment.updated', 'type' => $tipo, 'data' => ['id' => $dataId]],
            ['x-signature' => $firma ?? $this->firma($dataId, $requestId), 'x-request-id' => $requestId],
        );
    }

    private function firma(string $dataId, string $requestId): string
    {
        $ts = (string) now()->timestamp;

        return "ts={$ts},v1=".hash_hmac('sha256', FirmaWebhook::manifiesto($dataId, $requestId, $ts), self::SECRETO);
    }

    private function pagoPendiente(?Viaje $viaje = null): Pago
    {
        $viaje ??= $this->viaje;
        $this->fakePreferencia();
        $this->iniciar($this->cliente, $viaje)->assertCreated();
        // Las pruebas siguientes configuran sus propias respuestas.
        Http::swap(new FabricaHttp);

        return Pago::query()->where('viaje_id', $viaje->id)->sole();
    }

    private function otroViajeFinalizado(): Viaje
    {
        return Viaje::factory()->create([
            'cliente_id' => $this->cliente->persona_id,
            'conductor_id' => $this->conductor->persona_id,
            'estado' => EstadoViaje::Finalizado,
            'costo' => '1000.00',
            'costo_final' => '1000.00',
        ]);
    }

    private function fakePreferencia(): void
    {
        Http::fake([self::API.'/checkout/preferences' => fn (SolicitudHttp $s) => Http::response(
            $this->preferencia(str_starts_with($s['external_reference'], "viaje-{$this->viaje->id}-") ? 'pref-1' : 'pref-'.$s['external_reference']),
            201,
        )]);
    }

    /**
     * @return array<string, string>
     */
    private function preferencia(string $id): array
    {
        return [
            'id' => $id,
            'init_point' => "https://www.mercadopago.test/checkout?pref_id={$id}",
            'sandbox_init_point' => "https://sandbox.mercadopago.test/checkout?pref_id={$id}",
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function fakePago(array $datos): void
    {
        Http::fake([self::API.'/v1/payments/*' => Http::response($datos)]);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datosPago(Pago $pago, array $cambios = []): array
    {
        return $cambios + [
            'id' => 987654321,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => "viaje-{$pago->viaje_id}-pago-{$pago->id}",
            'currency_id' => 'ARS',
            'transaction_amount' => (float) $pago->monto,
            'payment_type_id' => 'account_money',
            'date_approved' => '2026-11-04T10:15:00.000-03:00',
        ];
    }
}
