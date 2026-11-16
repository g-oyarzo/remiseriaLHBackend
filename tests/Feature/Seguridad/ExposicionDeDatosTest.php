<?php

declare(strict_types=1);

namespace Tests\Feature\Seguridad;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Mensaje;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las respuestas de viajes y chat no deben filtrar datos personales de la
 * contraparte (DNI, teléfono, CUIL, domicilio, fecha de nacimiento, GPS).
 */
class ExposicionDeDatosTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Cuenta, 1: Cuenta, 2: Viaje}
     */
    private function viajeAceptado(): array
    {
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $vehiculo = Vehiculo::factory()->create();
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $conductorCuenta->persona_id, 'vehiculo_id' => $vehiculo->id]);

        $viaje = Viaje::factory()->create([
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
            'estado' => EstadoViaje::Aceptado,
        ]);

        return [$clienteCuenta, $conductorCuenta, $viaje];
    }

    public function test_el_cliente_no_recibe_datos_sensibles_del_conductor(): void
    {
        [$clienteCuenta, $conductorCuenta, $viaje] = $this->viajeAceptado();

        $response = $this->actingAs($clienteCuenta)->getJson("/api/v1/viajes/{$viaje->id}")->assertOk();

        $conductor = $response->json('data.conductor');
        $this->assertSame($conductorCuenta->persona_id, $conductor['persona_id']);
        $this->assertSame($conductorCuenta->persona->nombre, $conductor['persona']['nombre']);
        foreach (['cuil', 'fecha_nacimiento', 'domicilio_calle', 'domicilio_numero', 'ubicacion_actual'] as $campo) {
            $this->assertArrayNotHasKey($campo, $conductor);
        }
        $this->assertArrayNotHasKey('dni', $conductor['persona']);
        $this->assertArrayNotHasKey('telefono', $conductor['persona']);

        // El cliente sí necesita identificar el vehículo (CU 04).
        $response->assertJsonPath('data.vehiculo.patente', $viaje->vehiculo->patente);
    }

    public function test_el_conductor_no_recibe_dni_ni_telefono_del_cliente(): void
    {
        [$clienteCuenta, $conductorCuenta, $viaje] = $this->viajeAceptado();

        $response = $this->actingAs($conductorCuenta)->getJson("/api/v1/viajes/{$viaje->id}")->assertOk();

        $persona = $response->json('data.cliente.persona');
        $this->assertSame($clienteCuenta->persona->nombre, $persona['nombre']);
        $this->assertArrayNotHasKey('dni', $persona);
        $this->assertArrayNotHasKey('telefono', $persona);
    }

    public function test_el_listado_de_viajes_tampoco_filtra_datos_sensibles(): void
    {
        [$clienteCuenta] = $this->viajeAceptado();

        $response = $this->actingAs($clienteCuenta)->getJson('/api/v1/viajes')->assertOk();

        $this->assertArrayNotHasKey('cuil', $response->json('data.0.conductor'));
        $this->assertArrayNotHasKey('dni', $response->json('data.0.conductor.persona'));
        // Se conserva el formato de paginación de Laravel.
        $response->assertJsonStructure(['current_page', 'data', 'per_page', 'total']);
    }

    public function test_el_administrador_ve_los_datos_completos(): void
    {
        [, $conductorCuenta, $viaje] = $this->viajeAceptado();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)->getJson("/api/v1/viajes/{$viaje->id}")
            ->assertOk()
            ->assertJsonPath('data.conductor.cuil', $conductorCuenta->persona->conductor->cuil)
            ->assertJsonPath('data.cliente.persona.dni', $viaje->cliente->persona->dni);
    }

    public function test_el_historial_de_chat_no_expone_dni_ni_telefono(): void
    {
        [$clienteCuenta, $conductorCuenta, $viaje] = $this->viajeAceptado();
        Mensaje::factory()->create([
            'viaje_id' => $viaje->id,
            'emisor_persona_id' => $conductorCuenta->persona_id,
            'receptor_persona_id' => $clienteCuenta->persona_id,
        ]);

        $response = $this->actingAs($clienteCuenta)->getJson("/api/v1/viajes/{$viaje->id}/mensajes")->assertOk();

        $emisor = $response->json('data.0.emisor');
        $this->assertSame($conductorCuenta->persona->nombre, $emisor['nombre']);
        $this->assertArrayNotHasKey('dni', $emisor);
        $this->assertArrayNotHasKey('telefono', $emisor);
    }
}
