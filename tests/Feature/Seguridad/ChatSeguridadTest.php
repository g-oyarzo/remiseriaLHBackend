<?php

declare(strict_types=1);

namespace Tests\Feature\Seguridad;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Restricciones del chat: solo participantes envían y el contenido se sanea.
 */
class ChatSeguridadTest extends TestCase
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

    public function test_un_administrador_puede_leer_el_chat_pero_no_enviar_mensajes(): void
    {
        [, , $viaje] = $this->viajeAceptado();
        $admin = Cuenta::factory()->create(['rol' => RolPersona::Administrador]);

        $this->actingAs($admin)->getJson("/api/v1/viajes/{$viaje->id}/mensajes")->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/viajes/{$viaje->id}/mensajes", ['contenido' => 'Hola'])
            ->assertForbidden();

        $this->assertDatabaseCount('mensajes', 0);
    }

    public function test_un_mensaje_con_solo_caracteres_de_control_se_rechaza(): void
    {
        [$clienteCuenta, , $viaje] = $this->viajeAceptado();

        $this->actingAs($clienteCuenta)
            ->postJson("/api/v1/viajes/{$viaje->id}/mensajes", ['contenido' => "\u{0000}\u{0007}  \u{200B}"])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contenido');
    }

    public function test_un_tercero_no_puede_leer_ni_escribir_en_el_chat_de_un_viaje_ajeno(): void
    {
        [, , $viaje] = $this->viajeAceptado();
        $otroCliente = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);

        $this->actingAs($otroCliente)->getJson("/api/v1/viajes/{$viaje->id}/mensajes")->assertForbidden();
        $this->actingAs($otroCliente)
            ->postJson("/api/v1/viajes/{$viaje->id}/mensajes", ['contenido' => 'Hola'])
            ->assertForbidden();
    }
}
