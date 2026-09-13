<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Cuenta;
use App\Models\Persona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HALL-014: antes las reglas unique:personas,dni y unique:cuentas,email
 * generaban mensajes de error distintos según cuál de los dos campos
 * colisionara, permitiendo enumerar DNIs y emails registrados. Ahora ambos
 * casos devuelven el mismo mensaje genérico en los dos campos.
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function datosValidos(array $overrides = []): array
    {
        return array_merge([
            'dni' => '40123456',
            'nombre' => 'Ana',
            'apellido' => 'Pérez',
            'telefono' => '221-555-0199',
            'email' => 'ana.perez@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_registro_exitoso(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->datosValidos());

        $response->assertStatus(201);
        $this->assertDatabaseHas('personas', ['dni' => '40123456']);
        $this->assertDatabaseHas('cuentas', ['email' => 'ana.perez@example.com']);
    }

    public function test_dni_duplicado_devuelve_mensaje_generico(): void
    {
        Persona::factory()->create(['dni' => '40123456']);

        $response = $this->postJson('/api/v1/auth/register', $this->datosValidos());

        $response->assertStatus(422);
        $mensajeDni = $response->json('errors.dni.0');
        $mensajeEmail = $response->json('errors.email.0');

        // El mensaje debe ser el mismo genérico en ambos campos, sin
        // importar cuál de los dos realmente colisionó.
        $this->assertSame($mensajeDni, $mensajeEmail);
        $this->assertStringNotContainsString('dni ha sido', strtolower($mensajeDni));
    }

    public function test_email_duplicado_devuelve_el_mismo_mensaje_generico_que_dni_duplicado(): void
    {
        Cuenta::factory()->create(['email' => 'ana.perez@example.com']);

        $respuestaEmailDuplicado = $this->postJson('/api/v1/auth/register', $this->datosValidos());

        $respuestaEmailDuplicado->assertStatus(422);

        // Limpiar y repetir con un dni duplicado en su lugar; el mensaje
        // debe ser indistinguible entre ambos casos.
        Persona::query()->delete();
        Cuenta::query()->delete();
        Persona::factory()->create(['dni' => '40123456']);

        $respuestaDniDuplicado = $this->postJson('/api/v1/auth/register', $this->datosValidos([
            'email' => 'otro.email@example.com',
        ]));

        $respuestaDniDuplicado->assertStatus(422);

        $this->assertSame(
            $respuestaEmailDuplicado->json('errors.dni.0'),
            $respuestaDniDuplicado->json('errors.dni.0'),
        );
    }

    public function test_passwords_que_no_coinciden_fallan_validacion(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->datosValidos([
            'password_confirmation' => 'otra-cosa',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}
