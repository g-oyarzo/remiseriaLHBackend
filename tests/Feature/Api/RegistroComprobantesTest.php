<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\MetodoPago;
use App\Enums\RolPersona;
use App\Models\Comprobante;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Pago;
use App\Models\Viaje;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Registro de comprobantes (decisión del negocio): emisión única al
 * confirmarse el pago, archivo verificado por hash y acceso de la
 * administración solo para ver y descargar.
 */
class RegistroComprobantesTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $admin;

    private Cuenta $conductor;

    private Viaje $viaje;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $this->conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $this->conductor->persona_id]);
        $this->viaje = Viaje::factory()->create([
            'estado' => EstadoViaje::Finalizado,
            'conductor_id' => $this->conductor->persona_id,
            'costo' => '2500.00',
            'costo_final' => '2500.00',
        ]);
    }

    private function cobrarEnEfectivo(): Pago
    {
        $this->actingAs($this->conductor)->postJson("/api/v1/conductor/viajes/{$this->viaje->id}/pago", [
            'metodo_pago' => 'efectivo',
            'monto' => '2500.00',
        ])->assertCreated();

        return $this->viaje->fresh()->pago;
    }

    public function test_confirmar_un_pago_emite_el_comprobante_con_su_archivo_y_hash(): void
    {
        $pago = $this->cobrarEnEfectivo();

        $comprobante = Comprobante::query()->sole();
        $this->assertSame($pago->id, $comprobante->pago_id);
        $this->assertSame(sprintf('%08d', $pago->id), $comprobante->numero);
        $this->assertSame('2500.00', $comprobante->total);
        $this->assertSame('efectivo', $comprobante->metodo_pago);

        Storage::disk('comprobantes')->assertExists($comprobante->archivo);
        $this->assertSame($comprobante->sha256, hash('sha256', Storage::disk('comprobantes')->get($comprobante->archivo)));
    }

    public function test_un_pago_pendiente_no_emite_comprobante_hasta_conciliarse(): void
    {
        $this->actingAs($this->conductor)->postJson("/api/v1/conductor/viajes/{$this->viaje->id}/pago", [
            'metodo_pago' => 'qr',
            'monto' => '2500.00',
            'referencia_externa' => 'MP-123456',
        ])->assertCreated();
        $this->assertDatabaseCount('comprobantes', 0);

        $pago = $this->viaje->fresh()->pago;
        $this->actingAs($this->admin)->patchJson("/api/v1/admin/pagos/{$pago->id}/conciliar", ['estado' => 'confirmado'])->assertOk();

        $this->assertDatabaseCount('comprobantes', 1);
    }

    public function test_la_descarga_entrega_siempre_el_mismo_archivo(): void
    {
        $this->cobrarEnEfectivo();
        $this->travel(2)->days();

        $primera = $this->actingAs($this->admin)->get("/api/v1/viajes/{$this->viaje->id}/comprobante")->assertOk()->getContent();
        $segunda = $this->actingAs($this->admin)->get('/api/v1/admin/comprobantes/'.Comprobante::query()->sole()->id.'/descargar')->assertOk()->getContent();

        $this->assertSame($primera, $segunda);
        $this->assertSame(Comprobante::query()->sole()->sha256, hash('sha256', $segunda));
    }

    public function test_un_archivo_alterado_no_se_entrega(): void
    {
        $this->cobrarEnEfectivo();
        $comprobante = Comprobante::query()->sole();
        Storage::disk('comprobantes')->put($comprobante->archivo, '%PDF-1.4 alterado');

        $this->actingAs($this->admin)->getJson("/api/v1/admin/comprobantes/{$comprobante->id}/descargar")->assertStatus(409);
    }

    public function test_un_comprobante_emitido_no_se_puede_modificar_ni_eliminar(): void
    {
        $this->cobrarEnEfectivo();
        $comprobante = Comprobante::query()->sole();

        try {
            $comprobante->update(['total' => '1.00']);
            $this->fail('Se esperaba LogicException al modificar.');
        } catch (LogicException) {
        }

        try {
            $comprobante->delete();
            $this->fail('Se esperaba LogicException al eliminar.');
        } catch (LogicException) {
        }

        $this->assertSame('2500.00', $comprobante->fresh()->total);
    }

    public function test_no_existen_rutas_para_modificar_o_borrar_el_registro(): void
    {
        $this->cobrarEnEfectivo();
        $id = Comprobante::query()->sole()->id;

        $this->actingAs($this->admin)->putJson("/api/v1/admin/comprobantes/{$id}", ['total' => 1])->assertStatus(405);
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/comprobantes/{$id}")->assertStatus(405);
        $this->actingAs($this->admin)->postJson('/api/v1/admin/comprobantes', [])->assertStatus(405);
    }

    public function test_el_admin_lista_filtra_y_consulta_el_registro(): void
    {
        $pago = $this->cobrarEnEfectivo();
        $otro = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'costo' => '900.00']);
        Pago::factory()->create(['viaje_id' => $otro->id, 'monto' => '900.00', 'metodo_pago' => MetodoPago::Qr, 'estado' => EstadoPago::Confirmado]);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/comprobantes')->assertOk()->assertJsonPath('total', 2);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/comprobantes?metodo_pago=efectivo')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($this->admin)->getJson("/api/v1/admin/comprobantes?numero={$pago->id}")
            ->assertOk()
            ->assertJsonPath('data.0.viaje_id', $this->viaje->id);

        $id = Comprobante::query()->where('pago_id', $pago->id)->value('id');
        $this->actingAs($this->admin)->getJson("/api/v1/admin/comprobantes/{$id}")
            ->assertOk()
            ->assertJsonPath('data.numero', sprintf('%08d', $pago->id))
            ->assertJsonMissingPath('data.archivo');
    }

    public function test_solo_administradores_acceden_al_registro(): void
    {
        $this->cobrarEnEfectivo();
        $id = Comprobante::query()->sole()->id;

        $this->actingAs($this->conductor)->getJson('/api/v1/admin/comprobantes')->assertForbidden();
        $this->actingAs($this->conductor)->getJson("/api/v1/admin/comprobantes/{$id}/descargar")->assertForbidden();
    }

    public function test_no_se_puede_borrar_un_viaje_con_comprobante_emitido(): void
    {
        $this->cobrarEnEfectivo();

        $this->expectException(QueryException::class);

        Viaje::query()->whereKey($this->viaje->id)->delete();
    }
}
