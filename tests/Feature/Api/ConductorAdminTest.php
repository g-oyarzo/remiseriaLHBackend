<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoConductor;
use App\Enums\RolPersona;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HALL-011 (alta de conductor por admin), baja/reactivación lógica,
 * HALL-016 (límite en cercanos()) y HALL-017 (calificacion fuera de
 * $fillable).
 */
class ConductorAdminTest extends TestCase
{
    use RefreshDatabase;

    private function datosConductorValidos(array $overrides = []): array
    {
        return array_merge([
            'dni' => '30111222',
            'nombre' => 'Marcos',
            'apellido' => 'Gómez',
            'telefono' => '221-555-0100',
            'email' => 'marcos.gomez@example.com',
            'password' => 'contraseña-segura-123',
            'cuil' => '20301112223',
            'fecha_nacimiento' => '1990-05-12',
            'domicilio_localidad' => 'La Plata',
            'domicilio_calle' => 'Calle 50',
            'domicilio_numero' => '1234',
        ], $overrides);
    }

    public function test_admin_puede_dar_de_alta_un_conductor(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/conductores', $this->datosConductorValidos());

        $response->assertStatus(201);

        $this->assertDatabaseHas('personas', ['dni' => '30111222']);
        $this->assertDatabaseHas('conductores', ['cuil' => '20301112223']);
        $this->assertDatabaseHas('cuentas', [
            'email' => 'marcos.gomez@example.com',
            'rol' => RolPersona::Conductor->value,
        ]);
    }

    public function test_no_admin_no_puede_dar_de_alta_conductores(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $response = $this->actingAs($cliente)->postJson('/api/v1/admin/conductores', $this->datosConductorValidos());

        $response->assertStatus(403);
    }

    public function test_no_se_puede_asignar_un_vehiculo_ya_tomado_por_otro_conductor(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $vehiculo = Vehiculo::factory()->create();
        Conductor::factory()->create(['vehiculo_id' => $vehiculo->id]);

        $response = $this->actingAs($admin)->postJson(
            '/api/v1/admin/conductores',
            $this->datosConductorValidos(['vehiculo_id' => $vehiculo->id]),
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vehiculo_id']);
    }

    public function test_no_se_puede_dar_de_alta_con_dni_duplicado(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        \App\Models\Persona::factory()->create(['dni' => '30111222']);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/conductores', $this->datosConductorValidos());

        $response->assertStatus(422);
    }

    public function test_admin_puede_dar_de_baja_logica_a_un_conductor(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        $conductor = Conductor::factory()->create([
            'en_servicio' => true,
            'estado' => EstadoConductor::Activo,
        ]);

        $response = $this->actingAs($admin)->patchJson(
            "/api/v1/admin/conductores/{$conductor->persona_id}/estado",
            ['estado' => 'eliminado'],
        );

        $response->assertStatus(200);

        $conductorActualizado = $conductor->fresh();
        $this->assertSame(EstadoConductor::Eliminado, $conductorActualizado->estado);
        // Dar de baja debe sacarlo de servicio automáticamente.
        $this->assertFalse($conductorActualizado->en_servicio);
    }

    public function test_no_admin_no_puede_cambiar_estado_de_conductor(): void
    {
        $conductor = Conductor::factory()->create();
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $response = $this->actingAs($cliente)->patchJson(
            "/api/v1/admin/conductores/{$conductor->persona_id}/estado",
            ['estado' => 'eliminado'],
        );

        $response->assertStatus(403);
    }

    public function test_cercanos_respeta_el_limite_configurado(): void
    {
        // HALL-016: antes se devolvían todos los conductores disponibles
        // sin límite ni paginación.
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        Conductor::factory()->count(5)->create([
            'en_servicio' => true,
            'estado' => EstadoConductor::Activo,
            'vehiculo_id' => Vehiculo::factory(),
            'ubicacion_actual' => new \App\ValueObjects\Coordinate(lat: -34.9214, lng: -57.9544),
        ]);

        $response = $this->actingAs($admin)->getJson(
            '/api/v1/conductores/cercanos?lat=-34.9214&lng=-57.9544&radio_metros=5000&limite=2',
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_calificacion_no_es_mass_assignable(): void
    {
        // HALL-017: 'calificacion' se removió deliberadamente de $fillable
        // en el modelo Conductor.
        $conductor = new Conductor();

        $this->assertFalse($conductor->isFillable('calificacion'));
    }
}
