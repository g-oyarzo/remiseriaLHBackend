<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoPago;
use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobertura para HALL-003 (race condition en pagos), HALL-008 (fecha_pago) y
 * HALL-020 (sección "Testing insuficiente": no había ningún test de
 * PagoController).
 */
class PagoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function crearConductorConViajeFinalizado(): array
    {
        $vehiculo = Vehiculo::factory()->create();
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $viaje = Viaje::factory()->create([
            'estado' => EstadoViaje::Finalizado,
            'conductor_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        return [$conductorCuenta, $viaje];
    }

    public function test_conductor_asignado_puede_registrar_pago(): void
    {
        [$conductorCuenta, $viaje] = $this->crearConductorConViajeFinalizado();

        $response = $this->actingAs($conductorCuenta)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", [
            'metodo_pago' => 'efectivo',
            'monto' => 2500,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', EstadoPago::Confirmado->value);

        // HALL-008: fecha_pago debe quedar seteada explícitamente al
        // confirmar, no depender del useCurrent() de la migración.
        $this->assertDatabaseHas('pagos', [
            'viaje_id' => $viaje->id,
            'estado' => EstadoPago::Confirmado->value,
        ]);

        $pago = $viaje->fresh()->pago;
        $this->assertNotNull($pago->fecha_pago);
    }

    public function test_no_se_puede_registrar_un_segundo_pago_para_el_mismo_viaje(): void
    {
        // HALL-003: antes del fix, dos POST casi simultáneos podían generar
        // dos pagos (o un 500 no controlado). Sin un test de concurrencia
        // real (requeriría procesos separados golpeando la misma conexión),
        // esto valida al menos que la ruta de verificación dentro de la
        // transacción efectivamente detecta un pago ya existente y responde
        // 409 en lugar de duplicar o fallar con un error no controlado.
        [$conductorCuenta, $viaje] = $this->crearConductorConViajeFinalizado();

        $primera = $this->actingAs($conductorCuenta)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", [
            'metodo_pago' => 'efectivo',
            'monto' => 2500,
        ]);
        $primera->assertStatus(201);

        $segunda = $this->actingAs($conductorCuenta)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", [
            'metodo_pago' => 'tarjetaDebito',
            'monto' => 2500,
        ]);

        $segunda->assertStatus(409)
            ->assertJson(['message' => 'El viaje ya tiene un pago registrado.']);

        $this->assertSame(1, \App\Models\Pago::query()->where('viaje_id', $viaje->id)->count());
    }

    public function test_no_se_puede_registrar_pago_de_viaje_no_finalizado(): void
    {
        $vehiculo = Vehiculo::factory()->create();
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $viaje = Viaje::factory()->create([
            'estado' => EstadoViaje::EnCurso,
            'conductor_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $response = $this->actingAs($conductorCuenta)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", [
            'metodo_pago' => 'efectivo',
            'monto' => 2500,
        ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Solo se puede registrar un pago para viajes finalizados.']);
    }

    public function test_conductor_no_asignado_no_puede_registrar_pago(): void
    {
        [, $viaje] = $this->crearConductorConViajeFinalizado();

        $otroConductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $otroConductor->persona_id]);

        $response = $this->actingAs($otroConductor)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", [
            'metodo_pago' => 'efectivo',
            'monto' => 2500,
        ]);

        $response->assertStatus(403);
    }

    public function test_cliente_no_puede_registrar_pago(): void
    {
        [, $viaje] = $this->crearConductorConViajeFinalizado();

        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $response = $this->actingAs($clienteCuenta)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", [
            'metodo_pago' => 'efectivo',
            'monto' => 2500,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_puede_registrar_pago_de_cualquier_viaje(): void
    {
        [, $viaje] = $this->crearConductorConViajeFinalizado();

        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $response = $this->actingAs($admin)->postJson("/api/v1/conductor/viajes/{$viaje->id}/pago", [
            'metodo_pago' => 'qr',
            'monto' => 3000,
        ]);

        $response->assertStatus(201);
    }
}
