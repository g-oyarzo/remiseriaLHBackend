<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HALL-020 señalaba explícitamente que no había tests de cancelar(),
 * finalizar() ni calificar(). Esta clase los cubre, junto con HALL-005
 * (orden de autorización en iniciar()) y HALL-018 (recalculo de
 * calificación del conductor).
 */
class ViajeEstadosTest extends TestCase
{
    use RefreshDatabase;

    private function crearViajeAsignado(array $overrides = []): array
    {
        $vehiculo = Vehiculo::factory()->create();
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $viaje = Viaje::factory()->create(array_merge([
            'estado' => EstadoViaje::Aceptado,
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ], $overrides));

        return [$viaje, $clienteCuenta, $conductorCuenta];
    }

    // --- iniciar() / HALL-005 ---------------------------------------

    public function test_conductor_asignado_puede_iniciar_viaje_aceptado(): void
    {
        [$viaje, , $conductorCuenta] = $this->crearViajeAsignado();

        $response = $this->actingAs($conductorCuenta)->patchJson("/api/v1/conductor/viajes/{$viaje->id}/iniciar");

        $response->assertStatus(200);
        $this->assertSame(EstadoViaje::EnCurso, $viaje->fresh()->estado);
    }

    public function test_cliente_no_puede_iniciar_su_propio_viaje(): void
    {
        // HALL-005: antes se llamaba primero a authorizeAccess(), que para
        // un cliente devuelve true si es el dueño del viaje. La ruta ya
        // está protegida por role:conductor, pero esta prueba verifica la
        // capa de defensa en profundidad agregada directamente en el
        // controlador (independiente del middleware).
        [$viaje, $clienteCuenta] = $this->crearViajeAsignado();

        $response = $this->actingAs($clienteCuenta)->patchJson("/api/v1/conductor/viajes/{$viaje->id}/iniciar");

        $response->assertStatus(403);
        $this->assertSame(EstadoViaje::Aceptado, $viaje->fresh()->estado);
    }

    public function test_no_se_puede_iniciar_un_viaje_que_no_esta_aceptado(): void
    {
        [$viaje, , $conductorCuenta] = $this->crearViajeAsignado(['estado' => EstadoViaje::Solicitado]);

        $response = $this->actingAs($conductorCuenta)->patchJson("/api/v1/conductor/viajes/{$viaje->id}/iniciar");

        $response->assertStatus(409);
    }

    // --- finalizar() --------------------------------------------------

    public function test_conductor_asignado_puede_finalizar_viaje_en_curso(): void
    {
        [$viaje, , $conductorCuenta] = $this->crearViajeAsignado(['estado' => EstadoViaje::EnCurso]);

        $response = $this->actingAs($conductorCuenta)->patchJson("/api/v1/conductor/viajes/{$viaje->id}/finalizar");

        $response->assertStatus(200);
        $this->assertSame(EstadoViaje::Finalizado, $viaje->fresh()->estado);
    }

    public function test_conductor_no_asignado_no_puede_finalizar(): void
    {
        [$viaje] = $this->crearViajeAsignado(['estado' => EstadoViaje::EnCurso]);

        $otroConductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $otroConductor->persona_id]);

        $response = $this->actingAs($otroConductor)->patchJson("/api/v1/conductor/viajes/{$viaje->id}/finalizar");

        $response->assertStatus(403);
    }

    // --- cancelar() -----------------------------------------------------

    public function test_cliente_dueno_puede_cancelar_viaje(): void
    {
        [$viaje, $clienteCuenta] = $this->crearViajeAsignado(['estado' => EstadoViaje::Solicitado]);

        $response = $this->actingAs($clienteCuenta)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar");

        $response->assertStatus(200);
        $this->assertSame(EstadoViaje::Cancelado, $viaje->fresh()->estado);
    }

    public function test_conductor_no_puede_cancelar_viaje_asignado(): void
    {
        [$viaje, , $conductorCuenta] = $this->crearViajeAsignado();

        $response = $this->actingAs($conductorCuenta)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Los conductores no pueden cancelar viajes. Contacte al administrador.']);
        $this->assertSame(EstadoViaje::Aceptado, $viaje->fresh()->estado);
    }

    public function test_no_se_puede_cancelar_un_viaje_ya_finalizado(): void
    {
        [$viaje, $clienteCuenta] = $this->crearViajeAsignado(['estado' => EstadoViaje::Finalizado]);

        $response = $this->actingAs($clienteCuenta)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar");

        $response->assertStatus(409);
    }

    public function test_cliente_no_puede_cancelar_viaje_de_otro_cliente(): void
    {
        [$viaje] = $this->crearViajeAsignado(['estado' => EstadoViaje::Solicitado]);

        $otroCliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $otroCliente->persona_id]);

        $response = $this->actingAs($otroCliente)->patchJson("/api/v1/viajes/{$viaje->id}/cancelar");

        // ViajePolicy::ver() ya rechaza el acceso antes de llegar a la
        // verificación específica de "cliente dueño" (HALL-007 / sección
        // 4.3: autorización centralizada).
        $response->assertStatus(403);
        $this->assertSame(EstadoViaje::Solicitado, $viaje->fresh()->estado);
    }

    // --- calificar() / HALL-018 ------------------------------------------

    public function test_cliente_puede_calificar_viaje_finalizado_y_actualiza_promedio_conductor(): void
    {
        [$viaje, $clienteCuenta, $conductorCuenta] = $this->crearViajeAsignado(['estado' => EstadoViaje::Finalizado]);

        $response = $this->actingAs($clienteCuenta)->patchJson("/api/v1/cliente/viajes/{$viaje->id}/calificar", [
            'calificacion' => 5,
        ]);

        $response->assertStatus(200);
        $this->assertSame(5, $viaje->fresh()->calificacion);

        $conductor = Conductor::query()->find($conductorCuenta->persona_id);
        $this->assertSame(5.0, (float) $conductor->calificacion);
    }

    public function test_no_se_puede_calificar_dos_veces(): void
    {
        [$viaje, $clienteCuenta] = $this->crearViajeAsignado(['estado' => EstadoViaje::Finalizado]);

        $this->actingAs($clienteCuenta)
            ->patchJson("/api/v1/cliente/viajes/{$viaje->id}/calificar", ['calificacion' => 4])
            ->assertStatus(200);

        $response = $this->actingAs($clienteCuenta)
            ->patchJson("/api/v1/cliente/viajes/{$viaje->id}/calificar", ['calificacion' => 1]);

        $response->assertStatus(409);
        $this->assertSame(4, $viaje->fresh()->calificacion);
    }

    public function test_calificacion_promedia_correctamente_entre_varios_viajes_del_mismo_conductor(): void
    {
        // HALL-018: valida el resultado final del recálculo bajo lock
        // (dos viajes finalizados y calificados en secuencia deben dejar el
        // promedio correcto, no el de la última calificación pisando la
        // anterior).
        $vehiculo = Vehiculo::factory()->create();
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $cliente1 = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $cliente1->persona_id]);
        $cliente2 = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $cliente2->persona_id]);

        $viaje1 = Viaje::factory()->create([
            'estado' => EstadoViaje::Finalizado,
            'cliente_id' => $cliente1->persona_id,
            'conductor_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);
        $viaje2 = Viaje::factory()->create([
            'estado' => EstadoViaje::Finalizado,
            'cliente_id' => $cliente2->persona_id,
            'conductor_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $this->actingAs($cliente1)
            ->patchJson("/api/v1/cliente/viajes/{$viaje1->id}/calificar", ['calificacion' => 5])
            ->assertStatus(200);
        $this->actingAs($cliente2)
            ->patchJson("/api/v1/cliente/viajes/{$viaje2->id}/calificar", ['calificacion' => 3])
            ->assertStatus(200);

        $conductor = Conductor::query()->find($conductorCuenta->persona_id);
        $this->assertSame(4.0, (float) $conductor->calificacion);
    }
}
