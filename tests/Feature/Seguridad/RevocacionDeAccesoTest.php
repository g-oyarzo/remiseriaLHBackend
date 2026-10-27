<?php

declare(strict_types=1);

namespace Tests\Feature\Seguridad;

use App\Enums\RolPersona;
use App\Models\Conductor;
use App\Models\Cuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La baja lógica de un conductor corta su acceso a la API.
 */
class RevocacionDeAccesoTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_baja_de_un_conductor_revoca_sus_tokens(): void
    {
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create(['persona_id' => $conductorCuenta->persona_id]);
        $conductorCuenta->createToken('api-token');
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/conductores/{$conductorCuenta->persona_id}")
            ->assertOk();

        $this->assertSame(0, $conductorCuenta->tokens()->count());
    }

    public function test_un_conductor_dado_de_baja_no_puede_iniciar_sesion(): void
    {
        $conductorCuenta = Cuenta::factory()->create([
            'rol' => RolPersona::Conductor,
            'password' => 'password-segura',
        ]);
        Conductor::factory()->eliminado()->create(['persona_id' => $conductorCuenta->persona_id]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $conductorCuenta->email,
            'password' => 'password-segura',
        ])->assertForbidden();

        $this->assertSame(0, $conductorCuenta->tokens()->count());
    }
}
