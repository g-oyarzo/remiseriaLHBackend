<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Events\NuevoMensajeViaje;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * HALL-015: antes se permitía enviar mensajes a un viaje cancelado o
 * finalizado (solo se verificaba que tuviera conductor_id asignado).
 */
class MensajeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function crearViajeConEstado(EstadoViaje $estado): array
    {
        $vehiculo = Vehiculo::factory()->create();
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $viaje = Viaje::factory()->create([
            'estado' => $estado,
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => $conductorCuenta->persona_id,
            'vehiculo_id' => $vehiculo->id,
        ]);

        return [$viaje, $clienteCuenta, $conductorCuenta];
    }

    public function test_se_puede_chatear_en_viaje_aceptado(): void
    {
        // El envío exitoso dispara NuevoMensajeViaje (ShouldBroadcast). En
        // testing BROADCAST_CONNECTION=reverb a propósito (ver phpunit.xml,
        // lo necesita BroadcastingTest para probar canales privados), así
        // que sin Event::fake() esto intentaría conectarse a un Reverb real.
        Event::fake([NuevoMensajeViaje::class]);

        [$viaje, $clienteCuenta] = $this->crearViajeConEstado(EstadoViaje::Aceptado);

        $response = $this->actingAs($clienteCuenta)->postJson("/api/v1/viajes/{$viaje->id}/mensajes", [
            'contenido' => 'Ya salgo, ¿en qué esquina estás?',
        ]);

        $response->assertStatus(201);
    }

    public function test_se_puede_chatear_en_viaje_en_curso(): void
    {
        Event::fake([NuevoMensajeViaje::class]);

        [$viaje, $clienteCuenta] = $this->crearViajeConEstado(EstadoViaje::EnCurso);

        $response = $this->actingAs($clienteCuenta)->postJson("/api/v1/viajes/{$viaje->id}/mensajes", [
            'contenido' => 'Todo bien por acá.',
        ]);

        $response->assertStatus(201);
    }

    public function test_no_se_puede_chatear_en_viaje_finalizado(): void
    {
        [$viaje, $clienteCuenta] = $this->crearViajeConEstado(EstadoViaje::Finalizado);

        $response = $this->actingAs($clienteCuenta)->postJson("/api/v1/viajes/{$viaje->id}/mensajes", [
            'contenido' => 'Mensaje fuera de lugar.',
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('mensajes', 0);
    }

    public function test_no_se_puede_chatear_en_viaje_cancelado(): void
    {
        [$viaje, $clienteCuenta] = $this->crearViajeConEstado(EstadoViaje::Cancelado);

        $response = $this->actingAs($clienteCuenta)->postJson("/api/v1/viajes/{$viaje->id}/mensajes", [
            'contenido' => 'Mensaje fuera de lugar.',
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('mensajes', 0);
    }

    public function test_no_se_puede_chatear_en_viaje_sin_conductor_asignado(): void
    {
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $viaje = Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'cliente_id' => $clienteCuenta->persona_id,
            'conductor_id' => null,
        ]);

        $response = $this->actingAs($clienteCuenta)->postJson("/api/v1/viajes/{$viaje->id}/mensajes", [
            'contenido' => 'Hola?',
        ]);

        $response->assertStatus(409);
    }
}