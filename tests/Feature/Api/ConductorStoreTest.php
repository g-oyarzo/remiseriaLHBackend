<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RolPersona;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Marca;
use App\Models\Persona;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /api/v1/admin/conductores: alta de conductor con vehículo en una
 * única transacción.
 */
class ConductorStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/admin/conductores';

    private function admin(): Cuenta
    {
        return Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
    }

    /**
     * @return array<string, mixed>
     */
    private function datosValidos(array $overrides = []): array
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
            'vehiculo' => [
                'marca_id' => Marca::factory()->create()->id,
                'modelo' => 'Etios',
                'patente' => 'AB123CD',
                'color' => 'Blanco',
                'anio' => 2019,
            ],
        ], $overrides);
    }

    public function test_admin_crea_conductor_con_vehiculo_nuevo(): void
    {
        $response = $this->actingAs($this->admin())->postJson($this->endpoint, $this->datosValidos());

        $response->assertStatus(201)
            ->assertJsonPath('data.vehiculo.patente', 'AB123CD');

        $this->assertDatabaseHas('personas', ['dni' => '30111222']);
        $this->assertDatabaseHas('cuentas', ['email' => 'marcos.gomez@example.com', 'rol' => RolPersona::Conductor->value]);
        $this->assertDatabaseHas('vehiculos', ['patente' => 'AB123CD', 'estado' => 'operando']);

        $vehiculoId = Vehiculo::query()->where('patente', 'AB123CD')->value('id');
        $this->assertDatabaseHas('conductores', ['cuil' => '20301112223', 'vehiculo_id' => $vehiculoId]);
    }

    public function test_admin_crea_conductor_asignando_vehiculo_existente(): void
    {
        $vehiculo = Vehiculo::factory()->create();

        $response = $this->actingAs($this->admin())->postJson(
            $this->endpoint,
            $this->datosValidos(['vehiculo' => null, 'vehiculo_id' => $vehiculo->id]),
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('conductores', ['cuil' => '20301112223', 'vehiculo_id' => $vehiculo->id]);
        $this->assertSame(1, Vehiculo::query()->count());
    }

    public function test_crear_conductor_sin_vehiculo_es_valido(): void
    {
        $response = $this->actingAs($this->admin())->postJson(
            $this->endpoint,
            $this->datosValidos(['vehiculo' => null]),
        );

        $response->assertStatus(201);
        $this->assertDatabaseHas('conductores', ['cuil' => '20301112223', 'vehiculo_id' => null]);
    }

    public function test_vehiculo_id_y_vehiculo_son_excluyentes(): void
    {
        $vehiculo = Vehiculo::factory()->create();

        $response = $this->actingAs($this->admin())->postJson(
            $this->endpoint,
            $this->datosValidos(['vehiculo_id' => $vehiculo->id]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['vehiculo_id']);
        $this->assertDatabaseMissing('personas', ['dni' => '30111222']);
    }

    public function test_vehiculo_con_patente_duplicada_no_crea_nada(): void
    {
        Vehiculo::factory()->create(['patente' => 'AB123CD']);

        $response = $this->actingAs($this->admin())->postJson($this->endpoint, $this->datosValidos());

        $response->assertStatus(422)->assertJsonValidationErrors(['vehiculo.patente']);
        $this->assertDatabaseMissing('personas', ['dni' => '30111222']);
        $this->assertDatabaseMissing('conductores', ['cuil' => '20301112223']);
    }

    public function test_vehiculo_incompleto_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        unset($datos['vehiculo']['color']);

        $response = $this->actingAs($this->admin())->postJson($this->endpoint, $datos);

        $response->assertStatus(422)->assertJsonValidationErrors(['vehiculo.color']);
    }

    public function test_anio_de_vehiculo_fuera_de_rango_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['vehiculo']['anio'] = 1800;

        $response = $this->actingAs($this->admin())->postJson($this->endpoint, $datos);

        $response->assertStatus(422)->assertJsonValidationErrors(['vehiculo.anio']);
    }

    public function test_dni_duplicado_devuelve_422(): void
    {
        Persona::factory()->create(['dni' => '30111222']);

        $response = $this->actingAs($this->admin())->postJson($this->endpoint, $this->datosValidos());

        $response->assertStatus(422)->assertJsonValidationErrors(['dni']);
    }

    public function test_cuil_duplicado_devuelve_422(): void
    {
        Conductor::factory()->create(['cuil' => '20301112223']);

        $response = $this->actingAs($this->admin())->postJson($this->endpoint, $this->datosValidos());

        $response->assertStatus(422)->assertJsonValidationErrors(['cuil']);
    }

    public function test_email_duplicado_devuelve_422(): void
    {
        Cuenta::factory()->create(['email' => 'marcos.gomez@example.com']);

        $response = $this->actingAs($this->admin())->postJson($this->endpoint, $this->datosValidos());

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_si_falla_la_cuenta_se_revierte_persona_conductor_y_vehiculo(): void
    {
        // Simula un fallo en el último paso de la transacción. Ninguna de las
        // filas creadas antes (persona, vehículo, conductor) debe persistir.
        Cuenta::creating(function (): void {
            throw new \RuntimeException('fallo simulado al crear la cuenta');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->admin())->postJson($this->endpoint, $this->datosValidos());
            $this->fail('Se esperaba una excepción por el fallo simulado.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fallo simulado al crear la cuenta', $e->getMessage());
        } finally {
            // El listener es estático: se quita para no afectar otras pruebas.
            Cuenta::flushEventListeners();
        }

        $this->assertDatabaseMissing('personas', ['dni' => '30111222']);
        $this->assertDatabaseMissing('conductores', ['cuil' => '20301112223']);
        $this->assertDatabaseMissing('vehiculos', ['patente' => 'AB123CD']);
    }

    public function test_cliente_no_puede_crear_conductores(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $response = $this->actingAs($cliente)->postJson($this->endpoint, $this->datosValidos());

        $response->assertStatus(403);
        $this->assertDatabaseMissing('personas', ['dni' => '30111222']);
    }

    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $response = $this->postJson($this->endpoint, $this->datosValidos());

        $response->assertStatus(401);
    }
}
