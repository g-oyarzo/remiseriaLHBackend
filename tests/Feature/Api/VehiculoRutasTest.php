<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehiculoRutasTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_existe_la_baja_de_vehiculos(): void
    {
        $vehiculo = Vehiculo::factory()->create();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        // Antes respondía 500 (ruta registrada sin método en el controlador).
        $this->actingAs($admin)->deleteJson("/api/v1/admin/vehiculos/{$vehiculo->id}")->assertStatus(405);

        $this->assertModelExists($vehiculo);
    }

    public function test_el_administrador_lista_y_consulta_vehiculos(): void
    {
        $vehiculo = Vehiculo::factory()->create();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)->getJson('/api/v1/admin/vehiculos')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($admin)->getJson("/api/v1/admin/vehiculos/{$vehiculo->id}")->assertOk()->assertJsonPath('data.patente', $vehiculo->patente);
    }
}
