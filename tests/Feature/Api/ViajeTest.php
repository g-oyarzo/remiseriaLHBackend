<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EstadoViaje;
use App\Enums\RolPersona;
use App\Models\Cliente;
use App\Models\Conductor;
use App\Models\Cuenta;
use App\Models\Tarifa;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViajeTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_puede_solicitar_viaje(): void
    {
        $tarifa = Tarifa::factory()->create(['activa' => true]);
        $clienteCuenta = Cuenta::factory()->create(['rol' => RolPersona::Cliente]);
        Cliente::factory()->create(['persona_id' => $clienteCuenta->persona_id]);

        $response = $this->actingAs($clienteCuenta)->postJson('/api/v1/cliente/viajes', [
            'origen_lat' => -34.9200,
            'origen_lng' => -57.9500,
            'destino_lat' => -34.9300,
            'destino_lng' => -57.9400,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.estado', EstadoViaje::Solicitado->value);
            
        $this->assertDatabaseHas('viajes', [
            'cliente_id' => $clienteCuenta->persona_id,
            'estado' => EstadoViaje::Solicitado->value,
        ]);
    }

    public function test_conductor_puede_aceptar_viaje(): void
    {
        $tarifa = Tarifa::factory()->create(['activa' => true]);
        $viaje = Viaje::factory()->create([
            'estado' => EstadoViaje::Solicitado,
            'conductor_id' => null,
            'vehiculo_id' => null,
            'tarifa_id' => $tarifa->id,
        ]);

        $vehiculo = Vehiculo::factory()->create();
        $conductorCuenta = Cuenta::factory()->create(['rol' => RolPersona::Conductor]);
        Conductor::factory()->create([
            'persona_id' => $conductorCuenta->persona_id,
            'en_servicio' => true,
            'vehiculo_id' => $vehiculo->id,
            'estado' => 'activo'
        ]);

        $response = $this->actingAs($conductorCuenta)->patchJson("/api/v1/conductor/viajes/{$viaje->id}/aceptar");

        $response->assertStatus(200);
        $this->assertDatabaseHas('viajes', [
            'id' => $viaje->id,
            'conductor_id' => $conductorCuenta->persona_id,
            'estado' => EstadoViaje::Aceptado->value,
        ]);
    }
}