<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Pago;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CU 07 - Registrar pago: importe exacto calculado por el backend, reglas
 * por método, idempotencia, unicidad de referencias y conciliación.
 * Incluye la cobertura previa de HALL-003 (doble pago) y HALL-008.
 */
class PagoControllerTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $conductor;

    private Viaje $viaje;

    protected function setUp(): void
    {
        parent::setUp();

        $vehiculo = Vehiculo::factory()->create();
        $this->conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $this->conductor->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $this->viaje = $this->viajeFinalizado();
    }

    private function viajeFinalizado(array $atributos = []): Viaje
    {
        return Viaje::factory()->create($atributos + [
            'estado' => EstadoViaje::Finalizado,
            'conductor_id' => $this->conductor->persona_id,
            'costo' => '3000.00',
            'costo_final' => '3250.50',
        ]);
    }

    private function pagar(array $datos, ?Viaje $viaje = null, ?Cuenta $como = null): TestResponse
    {
        $viaje ??= $this->viaje;

        return $this->actingAs($como ?? $this->conductor)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", $datos);
    }

    private function admin(): Cuenta
    {
        return Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
    }

    public function test_el_efectivo_por_el_importe_exacto_queda_confirmado(): void
    {
        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => 3250.50])
            ->assertCreated()
            ->assertJsonPath('data.estado', EstadoPago::Confirmado->value)
            ->assertJsonPath('data.monto', '3250.50');

        $pago = $this->viaje->fresh()->pago;
        $this->assertNotNull($pago->fecha_pago);
        $this->assertSame($this->conductor->persona_id, $pago->registrado_por_persona_id);
        $this->assertSame('registro', $pago->historial[0]['accion']);
    }

    public function test_un_monto_distinto_al_importe_del_viaje_se_rechaza(): void
    {
        foreach (['3250.49', '3250.51', '1', '3000'] as $monto) {
            $this->pagar(['metodo_pago' => 'efectivo', 'monto' => $monto])
                ->assertUnprocessable()
                ->assertJsonPath('importe_esperado', '3250.50');
        }

        $this->assertDatabaseCount('pagos', 0);
    }

    public function test_montos_con_formato_invalido_se_rechazan(): void
    {
        foreach ([0, -3250.5, 'mil', 3250.505] as $monto) {
            $this->pagar(['metodo_pago' => 'efectivo', 'monto' => $monto])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('monto');
        }
    }

    public function test_un_viaje_finalizado_antes_del_calculo_final_cobra_lo_cotizado(): void
    {
        $legado = $this->viajeFinalizado(['costo_final' => null]);

        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => 3000], $legado)->assertCreated();
    }

    public function test_un_cobro_digital_queda_pendiente_hasta_conciliarse(): void
    {
        $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0001'])
            ->assertCreated()
            ->assertJsonPath('data.estado', EstadoPago::Pendiente->value)
            ->assertJsonPath('data.fecha_pago', null);
    }

    public function test_un_cobro_digital_exige_referencia_y_el_efectivo_no_la_admite(): void
    {
        $this->pagar(['metodo_pago' => 'tarjetaDebito', 'monto' => '3250.50'])
            ->assertUnprocessable()->assertJsonValidationErrors('referencia_externa');

        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => '3250.50', 'referencia_externa' => 'ABC-123'])
            ->assertUnprocessable()->assertJsonValidationErrors('referencia_externa');

        $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => "x'; DROP TABLE pagos;--"])
            ->assertUnprocessable()->assertJsonValidationErrors('referencia_externa');
    }

    public function test_la_cuenta_corriente_no_es_un_metodo_valido(): void
    {
        $this->pagar(['metodo_pago' => 'cuentaCorriente', 'monto' => '3250.50'])
            ->assertUnprocessable()->assertJsonValidationErrors('metodo_pago');
    }

    public function test_una_referencia_no_puede_reutilizarse_en_otro_viaje(): void
    {
        $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0001'])->assertCreated();

        $otroViaje = $this->viajeFinalizado();
        $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0001'], $otroViaje)
            ->assertStatus(409);

        $this->assertNull($otroViaje->fresh()->pago);
    }

    public function test_reenviar_el_mismo_pago_es_idempotente(): void
    {
        $datos = ['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0001'];

        $id = $this->pagar($datos)->assertCreated()->json('data.id');
        $this->pagar($datos)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, Pago::query()->where('viaje_id', $this->viaje->id)->count());
    }

    public function test_no_se_puede_registrar_un_segundo_pago_distinto(): void
    {
        // HALL-003: la verificación ocurre dentro de la transacción con el
        // viaje bloqueado; el unique(viaje_id) es la red de seguridad.
        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => '3250.50'])->assertCreated();

        $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0002'])
            ->assertStatus(409)
            ->assertJson(['message' => 'El viaje ya tiene un pago registrado.']);

        $this->assertSame(1, Pago::query()->where('viaje_id', $this->viaje->id)->count());
    }

    public function test_no_se_puede_registrar_pago_de_viaje_no_finalizado(): void
    {
        $enCurso = $this->viajeFinalizado(['estado' => EstadoViaje::EnCurso]);

        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => '3250.50'], $enCurso)
            ->assertStatus(409)
            ->assertJson(['message' => 'Solo se puede registrar un pago para viajes finalizados.']);
    }

    public function test_conductor_no_asignado_no_puede_registrar_pago(): void
    {
        $otroConductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $otroConductor->persona_id]);

        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => '3250.50'], como: $otroConductor)->assertForbidden();
    }

    public function test_cliente_no_puede_registrar_pago(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => '3250.50'], como: $cliente)->assertForbidden();
    }

    public function test_admin_puede_registrar_pago_de_cualquier_viaje(): void
    {
        $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0003'], como: $this->admin())
            ->assertCreated();
    }

    public function test_el_admin_confirma_un_cobro_digital(): void
    {
        $id = $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0001'])->json('data.id');
        $admin = $this->admin();

        $this->actingAs($admin)->patchJson("/api/v1/admin/pagos/{$id}/conciliar", ['estado' => 'confirmado'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmado')
            ->assertJsonPath('data.conciliado_por_persona_id', $admin->persona_id);

        $pago = Pago::query()->findOrFail($id);
        $this->assertNotNull($pago->fecha_pago);
        $this->assertCount(2, $pago->historial);

        // Un pago ya conciliado no se vuelve a conciliar.
        $this->actingAs($admin)->patchJson("/api/v1/admin/pagos/{$id}/conciliar", ['estado' => 'rechazado'])->assertStatus(409);
    }

    public function test_un_cobro_rechazado_puede_volver_a_registrarse_conservando_la_traza(): void
    {
        $id = $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0001'])->json('data.id');
        $this->actingAs($this->admin())->patchJson("/api/v1/admin/pagos/{$id}/conciliar", [
            'estado' => 'rechazado',
            'observacion' => 'Fondos insuficientes',
        ])->assertOk();

        $this->pagar(['metodo_pago' => 'efectivo', 'monto' => '3250.50'])
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.estado', 'confirmado');

        $acciones = array_column(Pago::query()->findOrFail($id)->historial, 'accion');
        $this->assertSame(['registro', 'conciliacion', 'reintento'], $acciones);
    }

    public function test_solo_administradores_concilian_y_listan_pagos(): void
    {
        $id = $this->pagar(['metodo_pago' => 'qr', 'monto' => '3250.50', 'referencia_externa' => 'MP-0001'])->json('data.id');

        $this->actingAs($this->conductor)->patchJson("/api/v1/admin/pagos/{$id}/conciliar", ['estado' => 'confirmado'])->assertForbidden();
        $this->actingAs($this->conductor)->getJson('/api/v1/admin/pagos')->assertForbidden();

        $this->actingAs($this->admin())->getJson('/api/v1/admin/pagos?estado=pendiente')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }
}
