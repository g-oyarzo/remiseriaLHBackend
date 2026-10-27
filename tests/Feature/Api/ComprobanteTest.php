<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CU 17 - Descargar comprobante.
 */
class ComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private Cuenta $cliente;

    private Cuenta $conductor;

    private Viaje $viaje;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $this->cliente->persona_id]);
        $vehiculo = Vehiculo::factory()->create(['patente' => 'AB123CD']);
        $this->conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $this->conductor->persona_id, 'vehiculo_id' => $vehiculo->id]);

        $this->viaje = Viaje::factory()->create([
            'cliente_id' => $this->cliente->persona_id,
            'conductor_id' => $this->conductor->persona_id,
            'vehiculo_id' => $vehiculo->id,
            'estado' => EstadoViaje::Finalizado,
            'origen_calle' => 'Calle 7',
            'origen_numero' => '800',
            'destino_calle' => 'Calle 50',
            'destino_numero' => '1200',
            'precio_base_aplicado' => '1500.00',
            'precio_por_km_aplicado' => '350.00',
            'distancia_recorrida_metros' => 5000,
            'distancia_final_km' => 5.0,
            'duracion_final_min' => 14,
            'costo_final' => '3250.00',
            'costo_final_origen' => 'recorrido',
        ]);
    }

    private function pagar(EstadoPago $estado = EstadoPago::Confirmado): Pago
    {
        return Pago::factory()->create([
            'viaje_id' => $this->viaje->id,
            'metodo_pago' => MetodoPago::Qr,
            'monto' => '3250.00',
            'referencia_externa' => 'MP-987654321',
            'estado' => $estado,
            'fecha_pago' => $estado === EstadoPago::Confirmado ? now() : null,
        ]);
    }

    public function test_el_cliente_descarga_el_comprobante_en_pdf(): void
    {
        $this->pagar();

        $respuesta = $this->actingAs($this->cliente)->get("/api/v1/viajes/{$this->viaje->id}/comprobante")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "attachment; filename=\"comprobante-viaje-{$this->viaje->id}.pdf\"");

        $pdf = $respuesta->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('AB123CD', $pdf);
        $this->assertStringContainsString('3.250,00', $pdf);
        $this->assertStringContainsString('Calle 50 1200', $pdf);
        $this->assertStringContainsString(mb_convert_encoding('No válido como factura', 'Windows-1252', 'UTF-8'), $pdf);
        // Referencia enmascarada.
        $this->assertStringContainsString('********4321', $pdf);
        $this->assertStringNotContainsString('MP-987654321', $pdf);
    }

    public function test_el_comprobante_no_incluye_dni_ni_telefono(): void
    {
        $this->pagar();
        $persona = $this->cliente->persona;
        $persona->update(['dni' => '30123456', 'telefono' => '2215551234']);

        $pdf = $this->actingAs($this->conductor)->get("/api/v1/viajes/{$this->viaje->id}/comprobante")->assertOk()->getContent();

        $this->assertStringContainsString(mb_convert_encoding($persona->nombre, 'Windows-1252', 'UTF-8'), $pdf);
        $this->assertStringNotContainsString('30123456', $pdf);
        $this->assertStringNotContainsString('2215551234', $pdf);
    }

    public function test_un_tercero_no_puede_descargar_el_comprobante(): void
    {
        $this->pagar();
        $otroCliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($otroCliente)->get("/api/v1/viajes/{$this->viaje->id}/comprobante")->assertForbidden();
    }

    public function test_un_administrador_puede_descargarlo(): void
    {
        $this->pagar();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)->get("/api/v1/viajes/{$this->viaje->id}/comprobante")->assertOk();
    }

    public function test_sin_pago_confirmado_no_hay_comprobante(): void
    {
        $this->actingAs($this->cliente)->getJson("/api/v1/viajes/{$this->viaje->id}/comprobante")->assertStatus(409);

        $this->pagar(EstadoPago::Pendiente);

        $this->actingAs($this->cliente)->getJson("/api/v1/viajes/{$this->viaje->id}/comprobante")->assertStatus(409);
    }

    public function test_el_registro_de_un_pago_confirmado_devuelve_la_url_del_comprobante(): void
    {
        $this->actingAs($this->conductor)->postJson("/api/v1/conductor/viajes/{$this->viaje->id}/pago", [
            'metodo_pago' => 'efectivo',
            'monto' => '3250.00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.comprobante_url', url("/api/v1/viajes/{$this->viaje->id}/comprobante"));
    }
}
