<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoConductor;
use App\Enums\RolPersona;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Marca;
use App\Models\Persona;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /api/v1/admin/conductores/{conductor}: reemplazo completo de datos
 * personales, cuenta y vehículo.
 */
class ConductorUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Cuenta
    {
        return Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
    }

    /**
     * Conductor con persona y cuenta de rol conductor.
     */
    private function conductorConCuenta(array $conductorOverrides = []): Conductor
    {
        $conductor = Conductor::factory()->create(array_merge([
            'cuil' => '20301112223',
            'estado' => EstadoConductor::Activo,
        ], $conductorOverrides));

        $conductor->persona()->update(['dni' => '30111222', 'nombre' => 'Marcos', 'apellido' => 'Gómez']);

        Cuenta::factory()->create([
            'persona_id' => $conductor->persona_id,
            'email' => 'marcos.gomez@example.com',
            'password' => 'contraseña-original-123',
            'rol' => RolPersona::Conductor,
        ]);

        return $conductor->fresh();
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
            'cuil' => '20301112223',
            'fecha_nacimiento' => '1990-05-12',
            'domicilio_localidad' => 'La Plata',
            'domicilio_calle' => 'Calle 50',
            'domicilio_numero' => '1234',
        ], $overrides);
    }

    private function url(Conductor $conductor): string
    {
        return "/api/v1/admin/conductores/{$conductor->persona_id}";
    }

    public function test_admin_actualiza_datos_personales_y_retorna_el_objeto(): void
    {
        $conductor = $this->conductorConCuenta();

        $response = $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos([
            'nombre' => 'Mariano',
            'domicilio_calle' => 'Avenida 7',
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('data.persona.nombre', 'Mariano')
            ->assertJsonPath('data.domicilio_calle', 'Avenida 7');

        $this->assertDatabaseHas('personas', ['id' => $conductor->persona_id, 'nombre' => 'Mariano']);
        $this->assertDatabaseHas('conductores', ['persona_id' => $conductor->persona_id, 'domicilio_calle' => 'Avenida 7']);
    }

    public function test_reenviar_los_mismos_datos_unicos_no_devuelve_422(): void
    {
        $conductor = $this->conductorConCuenta();

        $response = $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos());

        $response->assertStatus(200);
    }

    public function test_dni_de_otra_persona_devuelve_422(): void
    {
        $conductor = $this->conductorConCuenta();
        Persona::factory()->create(['dni' => '99999999']);

        $response = $this->actingAs($this->admin())->putJson(
            $this->url($conductor),
            $this->datosValidos(['dni' => '99999999']),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['dni']);
    }

    public function test_email_de_otra_cuenta_devuelve_422(): void
    {
        $conductor = $this->conductorConCuenta();
        Cuenta::factory()->create(['email' => 'otro@example.com']);

        $response = $this->actingAs($this->admin())->putJson(
            $this->url($conductor),
            $this->datosValidos(['email' => 'otro@example.com']),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_cuil_de_otro_conductor_devuelve_422(): void
    {
        $conductor = $this->conductorConCuenta();
        Conductor::factory()->create(['cuil' => '27999888877']);

        $response = $this->actingAs($this->admin())->putJson(
            $this->url($conductor),
            $this->datosValidos(['cuil' => '27999888877']),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['cuil']);
    }

    public function test_actualiza_el_vehiculo_asignado_sin_crear_otro(): void
    {
        $vehiculo = Vehiculo::factory()->create(['patente' => 'AA111AA']);
        $conductor = $this->conductorConCuenta(['vehiculo_id' => $vehiculo->id]);
        $vehiculosAntes = Vehiculo::query()->count();

        $response = $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos([
            'vehiculo' => [
                'marca_id' => Marca::factory()->create()->id,
                'modelo' => 'Corolla',
                'patente' => 'BB222BB',
                'color' => 'Gris',
                'anio' => 2021,
            ],
        ]));

        $response->assertStatus(200);
        $this->assertSame($vehiculosAntes, Vehiculo::query()->count());
        $this->assertDatabaseHas('vehiculos', ['id' => $vehiculo->id, 'patente' => 'BB222BB', 'modelo' => 'Corolla']);
        $this->assertDatabaseHas('conductores', ['persona_id' => $conductor->persona_id, 'vehiculo_id' => $vehiculo->id]);
    }

    public function test_crea_vehiculo_si_el_conductor_no_tenia_uno(): void
    {
        $conductor = $this->conductorConCuenta(['vehiculo_id' => null]);

        $response = $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos([
            'vehiculo' => [
                'marca_id' => Marca::factory()->create()->id,
                'modelo' => 'Etios',
                'patente' => 'CC333CC',
                'color' => 'Blanco',
                'anio' => 2019,
            ],
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseHas('vehiculos', ['patente' => 'CC333CC']);
        $this->assertNotNull($conductor->fresh()->vehiculo_id);
    }

    public function test_patente_de_otro_vehiculo_devuelve_422(): void
    {
        $conductor = $this->conductorConCuenta(['vehiculo_id' => Vehiculo::factory()->create(['patente' => 'AA111AA'])->id]);
        Vehiculo::factory()->create(['patente' => 'ZZ999ZZ']);

        $response = $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos([
            'vehiculo' => [
                'marca_id' => Marca::factory()->create()->id,
                'modelo' => 'Etios',
                'patente' => 'ZZ999ZZ',
                'color' => 'Blanco',
                'anio' => 2019,
            ],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['vehiculo.patente']);
    }

    public function test_omitir_vehiculo_desasigna_pero_conserva_el_vehiculo(): void
    {
        $vehiculo = Vehiculo::factory()->create();
        $conductor = $this->conductorConCuenta(['vehiculo_id' => $vehiculo->id]);

        $response = $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos());

        $response->assertStatus(200);
        $this->assertDatabaseHas('conductores', ['persona_id' => $conductor->persona_id, 'vehiculo_id' => null]);
        $this->assertDatabaseHas('vehiculos', ['id' => $vehiculo->id]);
    }

    public function test_asignar_vehiculo_ya_tomado_por_otro_conductor_devuelve_422(): void
    {
        $vehiculo = Vehiculo::factory()->create();
        Conductor::factory()->create(['vehiculo_id' => $vehiculo->id]);
        $conductor = $this->conductorConCuenta();

        $response = $this->actingAs($this->admin())->putJson(
            $this->url($conductor),
            $this->datosValidos(['vehiculo_id' => $vehiculo->id]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['vehiculo_id']);
    }

    public function test_reasignar_su_propio_vehiculo_es_valido(): void
    {
        $vehiculo = Vehiculo::factory()->create();
        $conductor = $this->conductorConCuenta(['vehiculo_id' => $vehiculo->id]);

        $response = $this->actingAs($this->admin())->putJson(
            $this->url($conductor),
            $this->datosValidos(['vehiculo_id' => $vehiculo->id]),
        );

        $response->assertStatus(200);
    }

    public function test_sin_password_se_mantiene_la_contrasena_actual(): void
    {
        $conductor = $this->conductorConCuenta();
        $hashAntes = Cuenta::query()->where('persona_id', $conductor->persona_id)->value('password');

        $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos())->assertStatus(200);

        $this->assertSame($hashAntes, Cuenta::query()->where('persona_id', $conductor->persona_id)->value('password'));
    }

    public function test_con_password_se_actualiza_la_contrasena_hasheada(): void
    {
        $conductor = $this->conductorConCuenta();

        $this->actingAs($this->admin())->putJson(
            $this->url($conductor),
            $this->datosValidos(['password' => 'nueva-contraseña-456']),
        )->assertStatus(200);

        $cuenta = Cuenta::query()->where('persona_id', $conductor->persona_id)->first();
        $this->assertTrue(Hash::check('nueva-contraseña-456', $cuenta->password));
    }

    public function test_no_degrada_el_rol_de_una_cuenta_administrador(): void
    {
        $conductor = $this->conductorConCuenta();
        Cuenta::query()->where('persona_id', $conductor->persona_id)->update(['rol' => RolPersona::Administrador->value]);

        $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos())->assertStatus(200);

        $this->assertDatabaseHas('cuentas', [
            'persona_id' => $conductor->persona_id,
            'rol' => RolPersona::Administrador->value,
        ]);
    }

    public function test_no_se_puede_modificar_un_conductor_eliminado(): void
    {
        $conductor = $this->conductorConCuenta(['estado' => EstadoConductor::Eliminado]);

        $response = $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos([
            'nombre' => 'Intento',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['estado']);
        $this->assertDatabaseMissing('personas', ['nombre' => 'Intento']);
    }

    public function test_si_falla_un_paso_no_se_aplica_ningun_cambio(): void
    {
        // Falla al actualizar la cuenta, último paso de la transacción: los
        // datos personales y del conductor escritos antes deben revertirse.
        $conductor = $this->conductorConCuenta();

        Cuenta::updating(function (): void {
            throw new \RuntimeException('fallo simulado al actualizar la cuenta');
        });

        $this->withoutExceptionHandling();

        try {
            // El email debe cambiar: un save sin atributos modificados no emite
            // el evento 'updating' y la excepción simulada nunca se dispararía.
            $this->actingAs($this->admin())->putJson($this->url($conductor), $this->datosValidos([
                'nombre' => 'NoDebePersistir',
                'domicilio_calle' => 'Calle que no debe persistir',
                'email' => 'nuevo.correo.rollback@example.com',
            ]));
            $this->fail('Se esperaba una excepción por el fallo simulado.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fallo simulado al actualizar la cuenta', $e->getMessage());
        } finally {
            Cuenta::flushEventListeners();
        }

        $this->assertDatabaseMissing('personas', ['nombre' => 'NoDebePersistir']);
        $this->assertDatabaseMissing('conductores', ['domicilio_calle' => 'Calle que no debe persistir']);
    }

    public function test_conductor_inexistente_devuelve_404(): void
    {
        $response = $this->actingAs($this->admin())->putJson('/api/v1/admin/conductores/999999', $this->datosValidos());

        $response->assertStatus(404);
    }

    public function test_cliente_no_puede_actualizar_conductores(): void
    {
        $conductor = $this->conductorConCuenta();
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $response = $this->actingAs($cliente)->putJson($this->url($conductor), $this->datosValidos([
            'nombre' => 'Intruso',
        ]));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('personas', ['nombre' => 'Intruso']);
    }
}
