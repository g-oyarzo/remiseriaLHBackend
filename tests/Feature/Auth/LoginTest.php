<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_correcto_devuelve_token(): void
    {
        $cuenta = Cuenta::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        // Asegurarse de que exista el perfil asociado
        Cliente::factory()->create(['persona_id' => $cuenta->persona_id]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'token',
                    'token_type',
                    'cuenta' => [
                        'id',
                        'email',
                        'rol',
                    ],
                ],
            ]);
    }

    public function test_login_con_credenciales_invalidas_falla(): void
    {
        Cuenta::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Credenciales inválidas.',
            ]);
    }

    public function test_acceso_a_ruta_protegida_sin_token_falla(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }
}
