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
 * GET /api/v1/admin/conductores: listado paginado con filtros por nombre,
 * DNI y estado, e incluyendo los datos del vehículo asociado.
 */
class ConductorIndexTest extends TestCase
{
    use RefreshDatabase;

    private string $endpoint = '/api/v1/admin/conductores';

    private function admin(): Cuenta
    {
        return Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
    }

    private function conductor(string $nombre, string $apellido, string $dni, EstadoConductor $estado = EstadoConductor::Activo, bool $conVehiculo = true): Conductor
    {
        $conductor = Conductor::factory()->create([
            'estado' => $estado,
            'vehiculo_id' => $conVehiculo ? Vehiculo::factory()->create()->id : null,
        ]);

        $conductor->persona()->update([
            'nombre' => $nombre,
            'apellido' => $apellido,
            'dni' => $dni,
        ]);

        return $conductor->fresh();
    }

    public function test_lista_paginada_con_datos_del_vehiculo(): void
    {
        $this->conductor('Marcos', 'Gómez', '30111222');
        $this->conductor('Lucía', 'Pérez', '31222333');

        $response = $this->actingAs($this->admin())->getJson($this->endpoint);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['persona_id', 'estado', 'persona' => ['dni', 'nombre', 'apellido'], 'vehiculo' => ['patente', 'marca']]],
                'current_page', 'last_page', 'per_page', 'total',
            ])
            ->assertJsonPath('total', 2);
    }

    public function test_per_page_limita_la_cantidad_de_resultados(): void
    {
        $this->conductor('A', 'Uno', '30000001');
        $this->conductor('B', 'Dos', '30000002');
        $this->conductor('C', 'Tres', '30000003');

        $response = $this->actingAs($this->admin())->getJson($this->endpoint.'?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_filtra_por_nombre_parcial(): void
    {
        $this->conductor('Marcos', 'Gómez', '30111222');
        $this->conductor('Lucía', 'Pérez', '31222333');

        $response = $this->actingAs($this->admin())->getJson($this->endpoint.'?nombre=marc');

        $response->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.persona.nombre', 'Marcos');
    }

    public function test_filtra_por_apellido(): void
    {
        $this->conductor('Marcos', 'Gómez', '30111222');
        $this->conductor('Lucía', 'Pérez', '31222333');

        $response = $this->actingAs($this->admin())->getJson($this->endpoint.'?nombre=Pérez');

        $response->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.persona.apellido', 'Pérez');
    }

    public function test_filtra_por_dni_parcial(): void
    {
        $this->conductor('Marcos', 'Gómez', '30111222');
        $this->conductor('Lucía', 'Pérez', '31222333');

        $response = $this->actingAs($this->admin())->getJson($this->endpoint.'?dni=3122');

        $response->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.persona.dni', '31222333');
    }

    public function test_filtra_por_estado(): void
    {
        $this->conductor('Activo', 'Uno', '30000001');
        $this->conductor('Baja', 'Dos', '30000002', EstadoConductor::Eliminado);

        $this->actingAs($this->admin())->getJson($this->endpoint.'?estado=eliminado')
            ->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.estado', 'eliminado');

        $this->actingAs($this->admin())->getJson($this->endpoint.'?estado=activo')
            ->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.estado', 'activo');
    }

    public function test_combina_filtros(): void
    {
        $this->conductor('Marcos', 'Gómez', '30111222');
        $this->conductor('Marcos', 'Ruiz', '30999888', EstadoConductor::Eliminado);

        $response = $this->actingAs($this->admin())->getJson($this->endpoint.'?nombre=Marcos&estado=activo');

        $response->assertStatus(200)->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.persona.apellido', 'Gómez');
    }

    public function test_comodines_sql_se_tratan_como_texto_literal(): void
    {
        $this->conductor('Marcos', 'Gómez', '30111222');

        // Sin escape, "%" coincidiría con cualquier nombre.
        $response = $this->actingAs($this->admin())->getJson($this->endpoint.'?nombre=%25');

        $response->assertStatus(200)->assertJsonPath('total', 0);
    }

    public function test_estado_invalido_devuelve_422(): void
    {
        $this->actingAs($this->admin())->getJson($this->endpoint.'?estado=inexistente')
            ->assertStatus(422)->assertJsonValidationErrors(['estado']);
    }

    public function test_per_page_fuera_de_rango_devuelve_422(): void
    {
        $this->actingAs($this->admin())->getJson($this->endpoint.'?per_page=500')
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_cliente_no_puede_listar_conductores(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($cliente)->getJson($this->endpoint)->assertStatus(403);
    }
}
