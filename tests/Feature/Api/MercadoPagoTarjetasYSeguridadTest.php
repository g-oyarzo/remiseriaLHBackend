<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Events\PagoActualizado;
use App\Events\ViajeCambioEstado;
use App\Jobs\CobrarViajeAutomaticamenteJob;
use App\Jobs\DesvincularMedioDePagoRemotoJob;
use App\Jobs\ReintegrarOperacionMercadoPagoJob;
use App\Jobs\VencerPreferenciaMercadoPagoJob;
use App\Listeners\IniciarCobroAutomatico;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\MedioPagoGuardado;
use App\Models\Pago;
use App\Models\Vehiculo;
use App\Models\Viaje;
use App\Services\Pagos\MercadoPago\CobrosMercadoPago;
use App\Services\Pagos\MercadoPago\FirmaWebhook;
use App\Services\Pagos\MercadoPago\MediosDePagoGuardados;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as SolicitudHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mejoras de Mercado Pago: ventana temporal y protección contra
 * repeticiones del webhook, reintegro de cobros no aplicados, tarjetas
 * guardadas, pago con tarjeta guardada, cobro automático (Orders API) y
 * conciliación de órdenes.
 *
 * Todas las respuestas de Mercado Pago son simuladas (Http::fake): estas
 * pruebas validan la lógica del backend, NO la integración real, que debe
 * verificarse en sandbox (docs/seguridad-y-despliegue.md).
 */
class MercadoPagoTarjetasYSeguridadTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-prueba';

    private const API = 'https://api.mercadopago.test';

    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private Cuenta $cliente;

    private Cuenta $conductor;

    private Viaje $viaje;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.access_token' => 'TEST-token-de-prueba',
            'services.mercadopago.public_key' => 'TEST-public-key',
            'services.mercadopago.webhook_secret' => self::SECRETO,
            'services.mercadopago.webhook_tolerancia_segundos' => 900,
            'services.mercadopago.notification_url' => 'https://remiseria.test/api/v1/webhooks/mercadopago',
            'services.mercadopago.base_url' => self::API,
            'services.mercadopago.sandbox' => true,
            'services.mercadopago.pagos_automaticos' => false,
            'services.mercadopago.reintegro_automatico' => false,
            'services.mercadopago.max_medios_por_cliente' => 5,
        ]);
        Http::preventStrayRequests();

        $this->cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente, 'email' => 'pasajero@example.test']);
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
            'metodo_pago' => MetodoPago::TarjetaCredito,
            'costo' => '3250.50',
            'costo_final' => '3250.50',
        ]);
    }

    // ---------------------------------------------------------------
    // Webhook: ventana temporal y repeticiones
    // ---------------------------------------------------------------

    public function test_una_firma_valida_pero_vieja_se_rechaza(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake([self::API.'/v1/payments/*' => Http::response($this->datosPago($pago))]);

        $viejo = (string) now()->subMinutes(16)->getTimestamp();
        $this->webhook('987654321', ts: $viejo)
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Notificación fuera de la ventana de tiempo permitida.');

        // Del futuro lejano tampoco.
        $this->webhook('987654321', ts: (string) now()->addMinutes(16)->getTimestamp())->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(EstadoPago::Pendiente, $pago->refresh()->estado);
    }

    public function test_acepta_el_ts_en_milisegundos_y_la_tolerancia_cero_desactiva_el_control(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake([self::API.'/v1/payments/*' => Http::response($this->datosPago($pago, ['status' => 'rejected']))]);

        $this->webhook('987654321', ts: (string) (now()->getTimestamp() * 1000))->assertOk();

        config(['services.mercadopago.webhook_tolerancia_segundos' => 0]);
        $this->webhook('987654321', ts: '1704908010')->assertOk();

        $this->assertSame(FirmaWebhook::VENCIDA, FirmaWebhook::verificar(
            'ts=1704908010,v1='.hash_hmac('sha256', 'id:1;ts:1704908010;', 's'), null, '1', 's', 60,
        ));
    }

    public function test_la_misma_notificacion_repetida_no_se_procesa_dos_veces(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake([self::API.'/v1/payments/*' => Http::response($this->datosPago($pago))]);
        $ts = (string) now()->getTimestamp();

        $this->webhook('987654321', ts: $ts, requestId: 'req-fijo')->assertOk()->assertJsonPath('message', 'Notificación recibida.');
        $this->webhook('987654321', ts: $ts, requestId: 'req-fijo')->assertOk()->assertJsonPath('message', 'Notificación ya procesada.');

        Http::assertSentCount(1);
        $this->assertSame(EstadoPago::Confirmado, $pago->refresh()->estado);
    }

    public function test_tras_un_503_el_reintento_de_la_misma_notificacion_si_se_procesa(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake([self::API.'/v1/payments/*' => Http::sequence()->push('error', 500)->push($this->datosPago($pago))]);
        $ts = (string) now()->getTimestamp();

        $this->webhook('987654321', ts: $ts, requestId: 'req-fijo')->assertStatus(503);
        $this->webhook('987654321', ts: $ts, requestId: 'req-fijo')->assertOk();

        $this->assertSame(EstadoPago::Confirmado, $pago->refresh()->estado);
    }

    // ---------------------------------------------------------------
    // Reintegro de cobros aprobados no aplicados
    // ---------------------------------------------------------------

    public function test_el_admin_reintegra_un_pago_tardio_tras_cambiar_a_efectivo(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake([self::API.'/checkout/preferences/*' => Http::response(['id' => 'pref-1'])]);
        $this->actingAs($this->conductor)
            ->postJson("/api/v1/conductor/viajes/{$this->viaje->id}/pago", ['metodo_pago' => 'efectivo', 'monto' => '3250.50'])
            ->assertCreated();

        Http::fake([
            self::API.'/v1/payments/987654321/refunds' => Http::response(['id' => 555, 'status' => 'approved'], 201),
            self::API.'/v1/payments/*' => Http::response($this->datosPago($pago)),
        ]);
        $this->webhook('987654321')->assertOk();
        $this->assertSame('mercadopago_aprobado_sin_aplicar', collect($pago->refresh()->historial)->last()['accion']);
        // Sin reintegro automático no se reintegra solo.
        Http::assertNotSent(fn (SolicitudHttp $s): bool => str_ends_with($s->url(), '/refunds'));

        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($this->conductor)->postJson("/api/v1/admin/pagos/{$pago->id}/reintegrar", ['mp_operacion_id' => '987654321'])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/admin/pagos/{$pago->id}/reintegrar", ['mp_operacion_id' => '111'])->assertConflict();

        $this->actingAs($admin)->postJson("/api/v1/admin/pagos/{$pago->id}/reintegrar", ['mp_operacion_id' => '987654321'])
            ->assertAccepted()->assertJsonPath('message', 'Reintegro solicitado a Mercado Pago.');
        $this->actingAs($admin)->postJson("/api/v1/admin/pagos/{$pago->id}/reintegrar", ['mp_operacion_id' => '987654321'])
            ->assertAccepted()->assertJsonPath('message', 'El reintegro ya se había solicitado.');

        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/payments/987654321/refunds'
            && $s->hasHeader('X-Idempotency-Key', 'reintegro-987654321'));
        Http::assertSentCount(2); // la consulta del webhook y un único reintegro

        $pago->refresh();
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
        $this->assertSame(MetodoPago::Efectivo, $pago->metodo_pago);
        $this->assertSame('reintegro_solicitado', collect($pago->historial)->last()['accion']);
    }

    public function test_nunca_reintegra_la_operacion_que_confirmo_el_pago(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake([self::API.'/v1/payments/*' => Http::response($this->datosPago($pago))]);
        $this->webhook('987654321')->assertOk();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)->postJson("/api/v1/admin/pagos/{$pago->id}/reintegrar", ['mp_operacion_id' => '987654321'])
            ->assertConflict();

        Http::assertNotSent(fn (SolicitudHttp $s): bool => str_ends_with($s->url(), '/refunds'));
    }

    public function test_con_reintegro_automatico_un_pago_duplicado_se_reintegra_solo(): void
    {
        config(['services.mercadopago.reintegro_automatico' => true]);
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake([
            self::API.'/v1/payments/222/refunds' => Http::response(['id' => 9], 201),
            self::API.'/v1/payments/111' => Http::response($this->datosPago($pago, ['id' => 111])),
            self::API.'/v1/payments/222' => Http::response($this->datosPago($pago, ['id' => 222])),
        ]);

        // El pasajero pagó dos veces el mismo enlace.
        $this->webhook('111')->assertOk();
        $this->webhook('222')->assertOk();

        $pago->refresh();
        $this->assertSame('111', $pago->referencia_externa);
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/payments/222/refunds');
        Http::assertNotSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/payments/111/refunds');
    }

    // ---------------------------------------------------------------
    // Tarjetas guardadas
    // ---------------------------------------------------------------

    public function test_la_configuracion_publica_expone_solo_la_clave_publica(): void
    {
        $this->actingAs($this->cliente)->getJson('/api/v1/pagos/mercadopago/configuracion')
            ->assertOk()
            ->assertJsonPath('data.public_key', 'TEST-public-key')
            ->assertJsonPath('data.tarjetas_guardadas', true)
            ->assertJsonPath('data.pagos_automaticos', false)
            ->assertJsonMissingPath('data.access_token');

        $this->assertStringNotContainsString('TEST-token-de-prueba', $this->actingAs($this->cliente)->getJson('/api/v1/pagos/mercadopago/configuracion')->getContent());
    }

    public function test_vincula_una_tarjeta_tokenizada_sin_exponer_identificadores_del_proveedor(): void
    {
        $this->fakeClienteYTarjeta();

        $respuesta = $this->vincular()->assertCreated()
            ->assertJsonPath('data.marca', 'visa')
            ->assertJsonPath('data.tipo', 'credit_card')
            ->assertJsonPath('data.ultimos_cuatro', '4242')
            ->assertJsonPath('data.vencimiento_mes', 11)
            ->assertJsonPath('data.vencimiento_anio', 2030)
            ->assertJsonPath('data.predeterminado', true)
            ->assertJsonPath('data.cobro_automatico', false)
            ->assertJsonPath('data.card_id', 'card-1')
            ->assertJsonMissingPath('data.mp_card_id')
            ->assertJsonMissingPath('data.mp_payment_profile_id')
            ->assertJsonMissingPath('data.consentimiento_ip')
            ->assertJsonMissingPath('data.mp_customer_id');

        $this->assertStringNotContainsString(self::TOKEN, $respuesta->getContent());

        $medio = MedioPagoGuardado::query()->sole();
        $this->assertSame('cus-1', $medio->mp_customer_id);
        $this->assertSame('card-1', $medio->mp_card_id);
        $this->assertSame('2026-11', $medio->consentimiento_version);
        $this->assertNotNull($medio->consentimiento_en);
        $this->assertSame('cus-1', Cliente::query()->find($this->cliente->persona_id)->mercadopago_customer_id);

        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/customers' && $s['email'] === 'pasajero@example.test');
        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/customers/cus-1/cards' && $s['token'] === self::TOKEN);

        // La misma tarjeta otra vez (Mercado Pago devuelve el mismo card_id).
        $this->vincular()->assertConflict()->assertJsonPath('message', 'Esta tarjeta ya está vinculada.');

        // Segunda tarjeta: reutiliza el customer y no la marca predeterminada.
        $this->resetHttp();
        $this->fakeClienteYTarjeta(cardId: 'card-2', ultimos: '1111');
        $this->vincular()->assertCreated()->assertJsonPath('data.predeterminado', false);
        Http::assertNotSent(fn (SolicitudHttp $s): bool => str_contains($s->url(), '/v1/customers/search'));
    }

    public function test_una_tarjeta_desvinculada_se_puede_volver_a_vincular(): void
    {
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1', 'desvinculado_en' => now()->subDay(), 'consentimiento_version' => '2025-01']);
        Cliente::query()->whereKey($this->cliente->persona_id)->update(['mercadopago_customer_id' => 'cus-1']);
        Http::fake([self::API.'/v1/customers/cus-1/cards' => Http::response($this->tarjeta('card-1', '4242'), 201)]);

        $this->vincular()->assertCreated()->assertJsonPath('data.id', $medio->id);

        $medio->refresh();
        $this->assertNull($medio->desvinculado_en);
        $this->assertSame('2026-11', $medio->consentimiento_version);
        $this->assertSame(1, MedioPagoGuardado::query()->count());
    }

    public function test_reutiliza_el_customer_existente_en_mercado_pago(): void
    {
        Http::fake([
            self::API.'/v1/customers/search*' => Http::response(['results' => [['id' => 'cus-existente']]]),
            self::API.'/v1/customers/cus-existente/cards' => Http::response($this->tarjeta('card-1', '4242')),
        ]);

        $this->vincular()->assertCreated();

        Http::assertNotSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/customers' && $s->method() === 'POST');
        $this->assertSame('cus-existente', MedioPagoGuardado::query()->sole()->mp_customer_id);
    }

    public function test_exige_consentimiento_y_solo_acepta_tokens(): void
    {
        $this->fakeClienteYTarjeta();

        $this->vincular(['acepto_guardar_tarjeta' => false])->assertUnprocessable()->assertJsonValidationErrors('acepto_guardar_tarjeta');
        $this->vincular(['token' => '4509 9535 6623 3704'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->vincular(['tipo' => 'cuenta'])->assertUnprocessable()->assertJsonValidationErrors('tipo');
        $this->vincular(['cobro_automatico' => true])->assertUnprocessable()->assertJsonValidationErrors('autorizo_cobro_automatico');

        Http::assertNothingSent();
        $this->assertSame(0, MedioPagoGuardado::query()->count());
    }

    public function test_el_cobro_automatico_requiere_la_habilitacion_comercial(): void
    {
        $this->fakeClienteYTarjeta();

        $this->vincular(['cobro_automatico' => true, 'autorizo_cobro_automatico' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Los cobros automáticos todavía no están habilitados. Podés guardar la tarjeta sin cobro automático.');

        Http::assertNothingSent();
    }

    public function test_si_mercado_pago_rechaza_la_tarjeta_responde_422_y_no_guarda_nada(): void
    {
        Http::fake([
            self::API.'/v1/customers/search*' => Http::response(['results' => []]),
            self::API.'/v1/customers' => Http::response(['id' => 'cus-1'], 201),
            self::API.'/v1/customers/cus-1/cards' => Http::response(['message' => 'invalid token'], 400),
        ]);

        $this->vincular()->assertUnprocessable();

        $this->assertSame(0, MedioPagoGuardado::query()->count());
    }

    public function test_limite_de_tarjetas_por_pasajero(): void
    {
        config(['services.mercadopago.max_medios_por_cliente' => 1]);
        $this->fakeClienteYTarjeta();

        $this->vincular()->assertCreated();
        $this->vincular()->assertUnprocessable();

        $this->assertSame(1, MedioPagoGuardado::query()->count());
    }

    public function test_listar_predeterminar_y_desvincular_solo_las_tarjetas_propias(): void
    {
        $primera = $this->medioGuardado(['predeterminado' => true, 'mp_card_id' => 'card-1']);
        $segunda = $this->medioGuardado(['mp_card_id' => 'card-2']);
        $otroCliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $otroCliente->persona_id]);
        $ajena = $this->medioGuardado(['cliente_id' => $otroCliente->persona_id, 'mp_card_id' => 'card-3']);

        $this->actingAs($this->cliente)->getJson('/api/v1/cliente/medios-pago')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $primera->id);

        // Otro pasajero no ve ni toca las tarjetas ajenas (404, no 403).
        $this->actingAs($otroCliente)->patchJson("/api/v1/cliente/medios-pago/{$primera->id}/predeterminado")->assertNotFound();
        $this->actingAs($otroCliente)->deleteJson("/api/v1/cliente/medios-pago/{$primera->id}")->assertNotFound();
        $this->actingAs($this->conductor)->getJson('/api/v1/cliente/medios-pago')->assertForbidden();

        $this->actingAs($this->cliente)->patchJson("/api/v1/cliente/medios-pago/{$segunda->id}/predeterminado")
            ->assertOk()->assertJsonPath('data.predeterminado', true);
        $this->assertFalse($primera->refresh()->predeterminado);

        Http::fake([self::API.'/v1/customers/cus-1/cards/card-2' => Http::response([], 200)]);
        $this->actingAs($this->cliente)->deleteJson("/api/v1/cliente/medios-pago/{$segunda->id}")->assertOk();

        Http::assertSent(fn (SolicitudHttp $s): bool => $s->method() === 'DELETE' && $s->url() === self::API.'/v1/customers/cus-1/cards/card-2');
        $this->assertNotNull($segunda->refresh()->desvinculado_en);
        // La predeterminada pasa a la otra tarjeta activa.
        $this->assertTrue($primera->refresh()->predeterminado);
        $this->assertFalse($ajena->refresh()->predeterminado);

        $this->actingAs($this->cliente)->getJson('/api/v1/cliente/medios-pago')->assertJsonCount(1, 'data');
    }

    public function test_si_mercado_pago_no_responde_al_desvincular_la_baja_local_rige_igual(): void
    {
        Queue::fake();
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);

        $this->actingAs($this->cliente)->deleteJson("/api/v1/cliente/medios-pago/{$medio->id}")->assertOk();

        $this->assertNotNull($medio->refresh()->desvinculado_en);
        Queue::assertPushed(DesvincularMedioDePagoRemotoJob::class);
    }

    // ---------------------------------------------------------------
    // Pago con tarjeta guardada (CIT con CVV)
    // ---------------------------------------------------------------

    public function test_paga_con_tarjeta_guardada_y_confirma_consultando_la_api(): void
    {
        Event::fake([PagoActualizado::class]);
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);
        Http::fake([
            self::API.'/v1/payments' => fn (SolicitudHttp $s) => Http::response(['id' => 777, 'status' => 'approved'], 201),
            self::API.'/v1/payments/777' => fn () => Http::response($this->datosPago(Pago::query()->sole(), ['id' => 777, 'payment_type_id' => 'credit_card'])),
        ]);

        $this->pagarConTarjeta($medio)
            ->assertOk()
            ->assertJsonPath('message', 'Pago aprobado.')
            ->assertJsonPath('data.estado', 'confirmado')
            ->assertJsonPath('data.mp_status', 'approved');

        $pago = Pago::query()->sole();
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
        $this->assertSame('777', $pago->referencia_externa);
        $this->assertSame(MetodoPago::TarjetaCredito, $pago->metodo_pago);

        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/payments' && $s->method() === 'POST'
            && $s['transaction_amount'] === 3250.5
            && $s['token'] === self::TOKEN
            && $s['installments'] === 1
            && $s['payer'] === ['type' => 'customer', 'id' => 'cus-1']
            && $s['external_reference'] === "viaje-{$this->viaje->id}-pago-{$pago->id}"
            && $s->hasHeader('X-Idempotency-Key', 'pago-'.$pago->id.'-tarjeta-'.hash('sha256', self::TOKEN)));
        // La confirmación sale de consultar el pago, no de la respuesta de creación.
        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/payments/777' && $s->method() === 'GET');
    }

    public function test_un_cargo_rechazado_deja_el_pago_pendiente(): void
    {
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);
        Http::fake([
            self::API.'/v1/payments' => Http::response(['id' => 778, 'status' => 'rejected'], 201),
            self::API.'/v1/payments/778' => fn () => Http::response($this->datosPago(Pago::query()->sole(), [
                'id' => 778, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount',
            ])),
        ]);

        $this->pagarConTarjeta($medio)
            ->assertOk()
            ->assertJsonPath('message', 'Mercado Pago rechazó el pago.')
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.mp_status_detail', 'cc_rejected_insufficient_amount');

        $this->assertSame(EstadoPago::Pendiente, Pago::query()->sole()->estado);
    }

    public function test_un_token_rechazado_por_mercado_pago_responde_422(): void
    {
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);
        Http::fake([self::API.'/v1/payments' => Http::response(['message' => 'invalid card token'], 400)]);

        $this->pagarConTarjeta($medio)->assertUnprocessable();
    }

    public function test_solo_el_pasajero_paga_con_sus_tarjetas(): void
    {
        $otroCliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $otroCliente->persona_id]);
        $medioAjeno = $this->medioGuardado(['cliente_id' => $otroCliente->persona_id, 'mp_card_id' => 'card-9']);
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);

        // Otro pasajero no puede pagar este viaje.
        $this->actingAs($otroCliente)->postJson("/api/v1/cliente/viajes/{$this->viaje->id}/pago/tarjeta", ['medio_pago_id' => $medioAjeno->id, 'token' => self::TOKEN])
            ->assertForbidden();
        // El pasajero no puede usar una tarjeta ajena.
        $this->pagarConTarjeta($medioAjeno)->assertNotFound();
        // El conductor no usa este endpoint.
        $this->actingAs($this->conductor)->postJson("/api/v1/cliente/viajes/{$this->viaje->id}/pago/tarjeta", ['medio_pago_id' => $medio->id, 'token' => self::TOKEN])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_un_segundo_cobro_simultaneo_se_rechaza(): void
    {
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);
        $candado = Cache::lock("pagos:viaje:{$this->viaje->id}:cobro", 120);
        $this->assertTrue($candado->get());

        $this->pagarConTarjeta($medio)->assertConflict();

        $candado->release();
        Http::assertNothingSent();
    }

    public function test_no_cobra_un_viaje_ya_pagado(): void
    {
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);
        Pago::factory()->create(['viaje_id' => $this->viaje->id, 'estado' => EstadoPago::Confirmado, 'metodo_pago' => MetodoPago::Efectivo, 'monto' => '3250.50']);

        $this->pagarConTarjeta($medio)->assertConflict();

        Http::assertNothingSent();
    }

    public function test_pagar_con_tarjeta_vence_el_enlace_vigente(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1']);
        Http::fake([
            self::API.'/checkout/preferences/pref-1' => Http::response(['id' => 'pref-1']),
            self::API.'/v1/payments' => Http::response(['id' => 777], 201),
            self::API.'/v1/payments/777' => fn () => Http::response($this->datosPago($pago, ['id' => 777])),
        ]);

        $this->pagarConTarjeta($medio)->assertOk();

        Http::assertSent(fn (SolicitudHttp $s): bool => $s->method() === 'PUT' && $s->url() === self::API.'/checkout/preferences/pref-1');
        $this->assertNull($pago->refresh()->preferencia_id);
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
    }

    // ---------------------------------------------------------------
    // Cobro automático (Orders API, sin CVV)
    // ---------------------------------------------------------------

    public function test_cobro_automatico_al_finalizar_el_viaje(): void
    {
        config(['services.mercadopago.pagos_automaticos' => true]);
        $this->medioGuardado(['mp_card_id' => null, 'mp_payment_profile_id' => 'prof-1', 'predeterminado' => true, 'cobro_automatico' => true]);
        Http::fake([
            self::API.'/v1/orders' => Http::response(['id' => 'ORD01ABC', 'status' => 'processing'], 201),
            self::API.'/v1/orders/ORD01ABC' => fn () => Http::response($this->orden(Pago::query()->sole())),
        ]);

        // El listener reacciona a la finalización del viaje.
        (new IniciarCobroAutomatico)->handle(new ViajeCambioEstado($this->viaje->id, EstadoViaje::Finalizado));

        $pago = Pago::query()->sole();
        $this->assertSame(EstadoPago::Confirmado, $pago->estado);
        $this->assertSame('ORD01ABC', $pago->mp_orden_id);
        $this->assertSame('ORD01ABC', $pago->referencia_externa);
        $this->assertSame(MetodoPago::TarjetaCredito, $pago->metodo_pago);

        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/orders' && $s->method() === 'POST'
            && $s['type'] === 'online'
            && $s['total_amount'] === '3250.50'
            && $s['payer'] === ['customer_id' => 'cus-1']
            && $s['transactions']['payments'][0]['automatic_payments']['payment_profile_id'] === 'prof-1'
            && $s['transactions']['payments'][0]['stored_credential']['payment_initiator'] === 'merchant'
            && $s->hasHeader('X-Idempotency-Key', 'cobro-automatico-pago-'.$pago->id));

        // Se intenta una sola vez por viaje.
        $this->assertSame('ya_intentado', app(CobrosMercadoPago::class)->cobrarAutomaticamente($this->viaje->id));
    }

    public function test_cobro_automatico_rechazado_avisa_y_deja_el_pago_pendiente(): void
    {
        config(['services.mercadopago.pagos_automaticos' => true]);
        Event::fake([PagoActualizado::class]);
        $this->medioGuardado(['mp_card_id' => null, 'mp_payment_profile_id' => 'prof-1', 'predeterminado' => true, 'cobro_automatico' => true]);
        Http::fake([
            self::API.'/v1/orders' => Http::response(['id' => 'ORD02', 'status' => 'failed'], 201),
            self::API.'/v1/orders/ORD02' => fn () => Http::response($this->orden(Pago::query()->sole(), ['id' => 'ORD02', 'status' => 'failed', 'status_detail' => 'insufficient_amount'])),
        ]);

        $this->assertSame('registrado', app(CobrosMercadoPago::class)->cobrarAutomaticamente($this->viaje->id));

        $pago = Pago::query()->sole();
        $this->assertSame(EstadoPago::Pendiente, $pago->estado);
        $this->assertSame('rejected', collect($pago->historial)->last()['mp_status']);
        Event::assertDispatched(PagoActualizado::class, fn (PagoActualizado $e): bool => $e->estado === EstadoPago::Pendiente);
    }

    public function test_no_hay_cobro_automatico_sin_habilitacion_sin_autorizacion_o_con_efectivo(): void
    {
        Queue::fake();
        $this->medioGuardado(['mp_card_id' => null, 'mp_payment_profile_id' => 'prof-1', 'predeterminado' => true, 'cobro_automatico' => true]);

        // Función deshabilitada: ni siquiera se encola.
        (new IniciarCobroAutomatico)->handle(new ViajeCambioEstado($this->viaje->id, EstadoViaje::Finalizado));
        Queue::assertNothingPushed();

        config(['services.mercadopago.pagos_automaticos' => true]);
        $cobros = app(CobrosMercadoPago::class);

        $this->viaje->update(['metodo_pago' => MetodoPago::Efectivo]);
        $this->assertSame('no_aplica', $cobros->cobrarAutomaticamente($this->viaje->id));

        $this->viaje->update(['metodo_pago' => MetodoPago::TarjetaCredito]);
        MedioPagoGuardado::query()->update(['cobro_automatico' => false]);
        $this->assertSame('no_aplica', $cobros->cobrarAutomaticamente($this->viaje->id));

        (new IniciarCobroAutomatico)->handle(new ViajeCambioEstado($this->viaje->id, EstadoViaje::Finalizado));
        Queue::assertPushed(CobrarViajeAutomaticamenteJob::class);
        Http::assertNothingSent();
    }

    public function test_el_webhook_de_ordenes_y_la_conciliacion_confirman_el_cobro_automatico(): void
    {
        config(['services.mercadopago.pagos_automaticos' => true]);
        $this->medioGuardado(['mp_card_id' => null, 'mp_payment_profile_id' => 'prof-1', 'predeterminado' => true, 'cobro_automatico' => true]);
        Http::fake([
            self::API.'/v1/orders' => Http::response(['id' => 'ORD03', 'status' => 'processing'], 201),
            self::API.'/v1/orders/ORD03' => fn () => Http::response($this->orden(Pago::query()->sole(), ['id' => 'ORD03', 'status' => 'processing'])),
        ]);
        app(CobrosMercadoPago::class)->cobrarAutomaticamente($this->viaje->id);
        $pago = Pago::query()->sole();
        $this->assertSame(EstadoPago::Pendiente, $pago->estado);

        // El webhook (tema "order") confirma tras consultar la orden.
        $this->resetHttp();
        Http::fake([self::API.'/v1/orders/ORD03' => Http::response($this->orden($pago, ['id' => 'ORD03']))]);
        $this->webhook('ORD03', tipo: 'order')->assertOk();
        $this->assertSame(EstadoPago::Confirmado, $pago->refresh()->estado);
    }

    public function test_la_conciliacion_consulta_las_ordenes_pendientes(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        $pago->update(['mp_orden_id' => 'ORD04']);
        Http::fake([
            self::API.'/v1/payments/search*' => Http::response(['results' => []]),
            self::API.'/v1/orders/ORD04' => Http::response($this->orden($pago, ['id' => 'ORD04'])),
        ]);

        $this->artisan('pagos:conciliar-mercadopago')->assertSuccessful();

        $this->assertSame(EstadoPago::Confirmado, $pago->refresh()->estado);
        $this->assertSame('ORD04', $pago->referencia_externa);
    }

    public function test_una_orden_que_no_es_la_del_pago_no_se_aplica(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        $pago->update(['mp_orden_id' => 'ORD-PROPIA']);

        $resultado = app(CobrosMercadoPago::class)->aplicarOrden($this->orden($pago, ['id' => 'ORD-AJENA']));

        $this->assertSame('requiere_revision', $resultado);
        $this->assertSame(EstadoPago::Pendiente, $pago->refresh()->estado);
    }

    public function test_vincular_con_cobro_automatico_crea_un_perfil_de_pago_y_desvincular_lo_cancela(): void
    {
        config(['services.mercadopago.pagos_automaticos' => true]);
        Http::fake([
            self::API.'/v1/customers/search*' => Http::response(['results' => []]),
            self::API.'/v1/customers' => Http::response(['id' => 'cus-1'], 201),
            self::API.'/v1/customers/cus-1/payment-profiles' => Http::response([
                'id' => 'prof-9', 'status' => 'READY',
                'payment_methods' => [['id' => 'visa', 'type' => 'credit_card', 'last_four_digits' => '4242']],
            ], 201),
            self::API.'/v1/customers/cus-1/payment-profiles/prof-9/cancel' => Http::response([], 202),
        ]);

        $this->vincular(['cobro_automatico' => true, 'autorizo_cobro_automatico' => true])
            ->assertCreated()
            ->assertJsonPath('data.cobro_automatico', true)
            ->assertJsonPath('data.card_id', null)
            ->assertJsonPath('data.ultimos_cuatro', '4242');

        Http::assertSent(fn (SolicitudHttp $s): bool => $s->url() === self::API.'/v1/customers/cus-1/payment-profiles'
            && $s['payment_methods'][0]['token'] === self::TOKEN
            && $s['payment_methods'][0]['default_method'] === true
            && $s->hasHeader('X-Idempotency-Key'));

        $medio = MedioPagoGuardado::query()->sole();
        $this->assertSame('prof-9', $medio->mp_payment_profile_id);
        $this->assertNull($medio->mp_card_id);

        // Un medio de cobro automático no sirve para pagar con CVV.
        $this->pagarConTarjeta($medio)->assertUnprocessable();

        $this->actingAs($this->cliente)->deleteJson("/api/v1/cliente/medios-pago/{$medio->id}")->assertOk();
        Http::assertSent(fn (SolicitudHttp $s): bool => $s->method() === 'POST'
            && $s->url() === self::API.'/v1/customers/cus-1/payment-profiles/prof-9/cancel');
        $this->assertFalse($medio->refresh()->cobro_automatico);
    }

    public function test_los_jobs_de_mercado_pago_no_fallan_si_el_proveedor_no_responde(): void
    {
        $pago = $this->pagoMercadoPagoPendiente();
        Http::fake(['*' => Http::response('error', 500)]);

        // Vencer un enlace: se reintenta más tarde y, si nunca se logra, solo se registra.
        (new VencerPreferenciaMercadoPagoJob('pref-1'))->handle(app(CobrosMercadoPago::class));

        // Reintegro automático de una operación que no figura como no aplicada: no reintegra nada.
        (new ReintegrarOperacionMercadoPagoJob($pago->id, '123'))->handle(app(CobrosMercadoPago::class));
        Http::assertNotSent(fn (SolicitudHttp $s): bool => str_contains($s->url(), '/refunds'));

        // Desvincular en Mercado Pago con el proveedor caído: la baja local ya rige.
        $medio = $this->medioGuardado(['mp_card_id' => 'card-1', 'desvinculado_en' => now()]);
        (new DesvincularMedioDePagoRemotoJob($medio->id))->handle(app(MediosDePagoGuardados::class));

        // Cobro automático con Mercado Pago caído: no aplica sin habilitación.
        (new CobrarViajeAutomaticamenteJob($this->viaje->id))->handle(app(CobrosMercadoPago::class));

        $this->assertSame(EstadoPago::Pendiente, $pago->refresh()->estado);
    }

    // ---------------------------------------------------------------

    private function pagoMercadoPagoPendiente(): Pago
    {
        Http::fake([self::API.'/checkout/preferences' => Http::response([
            'id' => 'pref-1',
            'init_point' => 'https://www.mercadopago.test/checkout?pref_id=pref-1',
            'sandbox_init_point' => 'https://sandbox.mercadopago.test/checkout?pref_id=pref-1',
        ], 201)]);
        $this->actingAs($this->cliente)->postJson("/api/v1/viajes/{$this->viaje->id}/pago/mercadopago")->assertCreated();
        $this->resetHttp();

        return Pago::query()->where('viaje_id', $this->viaje->id)->sole();
    }

    private function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function webhook(string $dataId, ?string $ts = null, ?string $requestId = null, string $tipo = 'payment'): TestResponse
    {
        $requestId ??= 'req-'.bin2hex(random_bytes(4));
        $ts ??= (string) now()->getTimestamp();
        $firma = "ts={$ts},v1=".hash_hmac('sha256', FirmaWebhook::manifiesto($dataId, $requestId, $ts), self::SECRETO);

        return $this->postJson(
            "/api/v1/webhooks/mercadopago?type={$tipo}&data.id={$dataId}",
            ['type' => $tipo, 'data' => ['id' => $dataId]],
            ['x-signature' => $firma, 'x-request-id' => $requestId],
        );
    }

    private function vincular(array $cambios = []): TestResponse
    {
        return $this->actingAs($this->cliente)->postJson('/api/v1/cliente/medios-pago', $cambios + [
            'token' => self::TOKEN,
            'payment_method_id' => 'visa',
            'tipo' => 'credit_card',
            'acepto_guardar_tarjeta' => true,
        ]);
    }

    private function pagarConTarjeta(MedioPagoGuardado $medio): TestResponse
    {
        return $this->actingAs($this->cliente)->postJson("/api/v1/cliente/viajes/{$this->viaje->id}/pago/tarjeta", [
            'medio_pago_id' => $medio->id,
            'token' => self::TOKEN,
        ]);
    }

    private function fakeClienteYTarjeta(string $cardId = 'card-1', string $ultimos = '4242'): void
    {
        Http::fake([
            self::API.'/v1/customers/search*' => Http::response(['results' => []]),
            self::API.'/v1/customers' => Http::response(['id' => 'cus-1'], 201),
            self::API.'/v1/customers/cus-1/cards' => Http::response($this->tarjeta($cardId, $ultimos), 201),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tarjeta(string $id, string $ultimos): array
    {
        return [
            'id' => $id,
            'last_four_digits' => $ultimos,
            'first_six_digits' => '450995',
            'expiration_month' => 11,
            'expiration_year' => 2030,
            'payment_method' => ['id' => 'visa', 'payment_type_id' => 'credit_card'],
            'issuer' => ['name' => 'Visa'],
        ];
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function medioGuardado(array $atributos = []): MedioPagoGuardado
    {
        return MedioPagoGuardado::query()->create($atributos + [
            'cliente_id' => $this->cliente->persona_id,
            'mp_customer_id' => 'cus-1',
            'mp_payment_method_id' => 'visa',
            'tipo' => 'credit_card',
            'ultimos_cuatro' => '4242',
            'predeterminado' => false,
            'cobro_automatico' => false,
            'consentimiento_en' => now(),
            'consentimiento_version' => '2026-11',
        ]);
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
            'date_approved' => '2026-11-15T10:15:00.000-03:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function orden(Pago $pago, array $cambios = []): array
    {
        return $cambios + [
            'id' => 'ORD01ABC',
            'type' => 'online',
            'status' => 'processed',
            'status_detail' => 'accredited',
            'external_reference' => "viaje-{$pago->viaje_id}-pago-{$pago->id}",
            'total_amount' => '3250.50',
            'last_updated_date' => '2026-11-15T10:15:00.000-03:00',
            'transactions' => ['payments' => [[
                'id' => 'PAY01',
                'amount' => '3250.50',
                'status' => 'processed',
                'payment_method' => ['id' => 'visa', 'type' => 'credit_card'],
            ]]],
        ];
    }
}
