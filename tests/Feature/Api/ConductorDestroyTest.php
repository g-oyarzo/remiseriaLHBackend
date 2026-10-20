<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoConductor;
use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Enums\TipoViaje;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DELETE /api/v1/admin/conductores/{conductor}: baja lógica con validación
 * de viajes activos y reservas programadas, y liberación del vehículo.
 * También cubre PATCH .../estado, que comparte la misma lógica de baja.
 */
class ConductorDestroyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Cuenta
    {
        return Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
    }

    private function conductorConVehiculo(): Conductor
    {
        $vehiculo = Vehiculo::factory()->create();

        return Conductor::factory()->create([
            'estado' => EstadoConductor::Activo,
            'en_servicio' => true,
            'vehiculo_id' => $vehiculo->id,
        ]);
    }

    private function viaje(Conductor $conductor, EstadoViaje $estado, TipoViaje $tipo = TipoViaje::Actual, ?string $fecha = null): Viaje
    {
        return Viaje::factory()->create([
            'conductor_id' => $conductor->persona_id,
            'estado' => $estado,
            'tipo' => $tipo,
            'fecha_viaje' => $fecha ?? now()->addDay(),
        ]);
    }

    private function url(Conductor $conductor): string
    {
        return "/api/v1/admin/conductores/{$conductor->persona_id}";
    }

    public function test_baja_logica_marca_eliminado_saca_de_servicio_y_libera_vehiculo(): void
    {
        $conductor = $this->conductorConVehiculo();
        $vehiculoId = $conductor->vehiculo_id;

        $response = $this->actingAs($this->admin())->deleteJson($this->url($conductor));

        $response->assertStatus(200);

        $this->assertDatabaseHas('conductores', [
            'persona_id' => $conductor->persona_id,
            'estado' => EstadoConductor::Eliminado->value,
            'en_servicio' => false,
            'vehiculo_id' => null,
        ]);
        // El registro no se borra y el vehículo sigue existiendo.
        $this->assertDatabaseHas('personas', ['id' => $conductor->persona_id]);
        $this->assertDatabaseHas('vehiculos', ['id' => $vehiculoId]);
    }

    public function test_baja_rechazada_con_viaje_aceptado(): void
    {
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::Aceptado);

        $response = $this->actingAs($this->admin())->deleteJson($this->url($conductor));

        $response->assertStatus(422)->assertJsonValidationErrors(['conductor']);
        $this->assertSame(EstadoConductor::Activo, $conductor->fresh()->estado);
        $this->assertNotNull($conductor->fresh()->vehiculo_id);
    }

    public function test_baja_rechazada_con_viaje_en_curso(): void
    {
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::EnCurso);

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))
            ->assertStatus(422)->assertJsonValidationErrors(['conductor']);

        $this->assertSame(EstadoConductor::Activo, $conductor->fresh()->estado);
    }

    public function test_baja_rechazada_con_reserva_programada_solicitada_a_futuro(): void
    {
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::Solicitado, TipoViaje::Programado, now()->addDays(2)->toDateTimeString());

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))
            ->assertStatus(422)->assertJsonValidationErrors(['conductor']);

        $this->assertSame(EstadoConductor::Activo, $conductor->fresh()->estado);
    }

    public function test_baja_rechazada_con_reserva_programada_aceptada_a_futuro(): void
    {
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::Aceptado, TipoViaje::Programado, now()->addDays(2)->toDateTimeString());

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))
            ->assertStatus(422);
    }

    public function test_reserva_programada_vencida_no_bloquea_la_baja(): void
    {
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::Solicitado, TipoViaje::Programado, now()->subDays(2)->toDateTimeString());

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))->assertStatus(200);
    }

    public function test_viaje_solicitado_inmediato_no_bloquea_la_baja(): void
    {
        // Decisión de negocio: solo se bloquean viajes ya aceptados o en curso,
        // y reservas programadas vigentes. Un pedido inmediato todavía no
        // asignado no impide la baja.
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::Solicitado, TipoViaje::Actual);

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))->assertStatus(200);
    }

    public function test_viajes_finalizados_o_cancelados_no_bloquean_la_baja(): void
    {
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::Finalizado);
        $this->viaje($conductor, EstadoViaje::Cancelado, TipoViaje::Programado, now()->addDays(3)->toDateTimeString());

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))->assertStatus(200);
    }

    public function test_conductor_ya_eliminado_devuelve_422(): void
    {
        $conductor = $this->conductorConVehiculo();
        $conductor->update(['estado' => EstadoConductor::Eliminado]);

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))
            ->assertStatus(422)->assertJsonValidationErrors(['estado']);
    }

    public function test_cliente_no_puede_dar_de_baja_conductores(): void
    {
        $conductor = $this->conductorConVehiculo();
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->deleteJson($this->url($conductor))->assertStatus(403);
        $this->assertSame(EstadoConductor::Activo, $conductor->fresh()->estado);
    }

    public function test_conductor_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->admin())->deleteJson('/api/v1/admin/conductores/999999')->assertStatus(404);
    }

    public function test_el_vehiculo_liberado_puede_asignarse_a_otro_conductor(): void
    {
        $conductor = $this->conductorConVehiculo();
        $vehiculoId = $conductor->vehiculo_id;

        $this->actingAs($this->admin())->deleteJson($this->url($conductor))->assertStatus(200);

        $this->assertFalse(Conductor::query()->where('vehiculo_id', $vehiculoId)->exists());
    }

    public function test_patch_estado_eliminado_aplica_las_mismas_validaciones_de_viajes(): void
    {
        $conductor = $this->conductorConVehiculo();
        $this->viaje($conductor, EstadoViaje::Aceptado);

        $this->actingAs($this->admin())->patchJson(
            "/api/v1/admin/conductores/{$conductor->persona_id}/estado",
            ['estado' => 'eliminado'],
        )->assertStatus(422);

        $this->assertSame(EstadoConductor::Activo, $conductor->fresh()->estado);
    }

    public function test_patch_estado_eliminado_libera_vehiculo_si_no_hay_viajes(): void
    {
        $conductor = $this->conductorConVehiculo();

        $this->actingAs($this->admin())->patchJson(
            "/api/v1/admin/conductores/{$conductor->persona_id}/estado",
            ['estado' => 'eliminado'],
        )->assertStatus(200);

        $this->assertDatabaseHas('conductores', [
            'persona_id' => $conductor->persona_id,
            'estado' => EstadoConductor::Eliminado->value,
            'vehiculo_id' => null,
        ]);
    }

    public function test_patch_reactivar_no_modifica_el_vehiculo(): void
    {
        $conductor = $this->conductorConVehiculo();
        $conductor->update(['estado' => EstadoConductor::Eliminado]);
        $vehiculoId = $conductor->vehiculo_id;

        $this->actingAs($this->admin())->patchJson(
            "/api/v1/admin/conductores/{$conductor->persona_id}/estado",
            ['estado' => 'activo'],
        )->assertStatus(200);

        $this->assertSame(EstadoConductor::Activo, $conductor->fresh()->estado);
        $this->assertSame($vehiculoId, $conductor->fresh()->vehiculo_id);
    }
}
