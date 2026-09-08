<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\RolPersona;
use App\Models\Cuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_no_puede_acceder_a_rutas_de_admin(): void
    {
        $cliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        
        $response = $this->actingAs($cliente)->getJson('/api/v1/admin/vehiculos');
        
        $response->assertStatus(403);
    }

    public function test_conductor_no_puede_acceder_a_rutas_de_cliente(): void
    {
        $conductor = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        
        $response = $this->actingAs($conductor)->postJson('/api/v1/cliente/viajes', [
            'origen_lat' => -34.92,
            'origen_lng' => -57.95,
            'destino_lat' => -34.93,
            'destino_lng' => -57.94,
        ]);
        
        $response->assertStatus(403);
    }

    public function test_admin_puede_acceder_a_sus_rutas(): void
    {
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);
        
        $response = $this->actingAs($admin)->getJson('/api/v1/admin/vehiculos');
        
        $response->assertStatus(200);
    }
}
